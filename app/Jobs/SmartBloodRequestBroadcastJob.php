<?php

namespace App\Jobs;

use App\Models\BloodRequest;
use App\Models\DonorResponseLog;
use App\Models\User;
use App\Services\DfiCalculationService;
use App\Services\EligibilityService;
use App\Services\Matching\DonorCandidate;
use App\Services\Matching\DonorCandidateFinder;
use App\Services\Matching\ResponseRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Main dispatch path for normal and urgent requests (BloodRequestController).
 * Candidates come from DonorCandidateFinder (hard rules); this job applies the
 * soft rules (fatigue, quiet hours) and the score, then hands the top K to
 * DispatchEmergencyAlertsJob.
 */
class SmartBloodRequestBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_DISTANCE_KM = 20.0;
    private const QUIET_HOURS_START = 23;
    private const QUIET_HOURS_END = 6;
    private const MAX_DFI_THRESHOLD = 70.0;
    private const QUIET_HOURS_MIN_RESPONSE_RATE = 0.2;
    private const DEFAULT_TOP_K = 15;
    private const DEFAULT_CANDIDATE_LIMIT = 200;

    public function __construct(
        public int $bloodRequestId,
        public int $topK = self::DEFAULT_TOP_K
    ) {}

    public function handle(
        DfiCalculationService $dfiService,
        ?DonorCandidateFinder $finder = null,
        ?ResponseRateService $responseRates = null,
    ): void {
        $finder ??= app(DonorCandidateFinder::class);
        $responseRates ??= app(ResponseRateService::class);

        $bloodRequest = BloodRequest::with(['district', 'upazila', 'hospital'])->find($this->bloodRequestId);
        if (!$bloodRequest) {
            return;
        }

        $now = now();
        $isSuperCritical = (bool) $bloodRequest->is_super_critical;
        $isQuietHours = $this->isQuietHours($now) && !$isSuperCritical;

        // Super-critical requests skip the donor's calendar but still respect an explicit "not available".
        $candidates = $finder->find(
            $bloodRequest,
            radiusKm: self::MAX_DISTANCE_KM,
            limit: $this->candidateLimit(),
            respectAvailabilityToggle: true,
            respectCalendar: !$isSuperCritical,
        );

        if ($candidates->isEmpty()) {
            return;
        }

        $candidateIds = $candidates->map(fn (DonorCandidate $c) => (int) $c->user->id)->all();
        $rates = $responseRates->forDonors($candidateIds);
        $ignoredCounts = $this->getIgnoredCounts($candidateIds, DfiCalculationService::DEFAULT_IGNORE_AFTER_HOURS);

        $ranked = [];

        foreach ($candidates as $candidate) {
            $donor = $candidate->user;
            $donorId = (int) $donor->id;
            $ignored = (int) ($ignoredCounts[$donorId] ?? 0);
            $dfiScore = $dfiService->calculateForDonor($donor, $ignored, $now);
            $responseRate = $rates[$donorId];

            if (!$isSuperCritical) {
                if ($dfiScore > self::MAX_DFI_THRESHOLD) {
                    continue;
                }

                if ($isQuietHours && $responseRate < self::QUIET_HOURS_MIN_RESPONSE_RATE) {
                    continue;
                }
            }

            $ranked[] = [
                'donor_id' => $donorId,
                'distance_km' => $candidate->distanceKm,
                'days_since_last_donation' => app(EligibilityService::class)->daysSinceLastDonation($donor, $bloodRequest->component_type, $now),
                'temporal_hour' => (int) $now->hour,
                'is_weekend' => (bool) $now->isWeekend(),
                'historical_response_rate' => $responseRate,
                'is_identical_group' => $candidate->isIdenticalGroup,
                'rank_score' => $this->rankScore($responseRate, $candidate, $dfiScore, $isSuperCritical, (bool) $donor->is_ready_now),
            ];

            if (abs((float) $donor->dfi_score - $dfiScore) > 0.01) {
                User::where('id', $donorId)->update(['dfi_score' => $dfiScore]);
            }
        }

        if ($ranked === []) {
            return;
        }

        usort($ranked, fn (array $a, array $b) => $b['rank_score'] <=> $a['rank_score']);
        $topK = array_slice($ranked, 0, max(1, $this->topK));

        DispatchEmergencyAlertsJob::dispatch(
            bloodRequestId: $bloodRequest->id,
            rankedDonors: $topK
        )->afterCommit();
    }

    /**
     * @param array<int> $donorIds
     * @return array<int, int>
     */
    private function getIgnoredCounts(array $donorIds, int $afterHours): array
    {
        if ($donorIds === []) {
            return [];
        }

        $cutoff = now()->subHours($afterHours);

        return DonorResponseLog::query()
            ->whereIn('donor_id', $donorIds)
            ->whereIn('status', ['pending', 'ignored'])
            ->where('notified_at', '<=', $cutoff)
            ->selectRaw('donor_id, COUNT(*) AS ignored_count')
            ->groupBy('donor_id')
            ->pluck('ignored_count', 'donor_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function isQuietHours(Carbon $now): bool
    {
        $hour = (int) $now->hour;
        return $hour >= self::QUIET_HOURS_START || $hour <= self::QUIET_HOURS_END;
    }

    /**
     * Unknown distance is scored as config('matching.assumed_distance_km'), so a donor
     * known to be nearby still beats one whose location we do not know.
     */
    private function rankScore(float $responseRate, DonorCandidate $candidate, float $dfiScore, bool $isSuperCritical, bool $isReadyNow): float
    {
        $score = ($responseRate * 100) - (($candidate->distanceKm ?? (float) config('matching.assumed_distance_km', 10.0)) * 2);

        if (!$candidate->isIdenticalGroup) {
            $score -= (float) config('matching.compatible_group_penalty', 30);
        }

        if (!$isSuperCritical) {
            $score -= $dfiScore;
        }

        if ($isReadyNow) {
            $score += 5;
        }

        return round($score, 2);
    }

    private function candidateLimit(): int
    {
        $limit = max(self::DEFAULT_CANDIDATE_LIMIT, $this->topK * 10);
        return min($limit, 500);
    }
}
