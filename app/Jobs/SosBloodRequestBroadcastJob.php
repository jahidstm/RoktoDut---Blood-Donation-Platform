<?php

namespace App\Jobs;

use App\Models\BloodRequest;
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

/**
 * SosBloodRequestBroadcastJob
 *
 * Dedicated high-priority job for Emergency SOS requests.
 * Key differences from SmartBloodRequestBroadcastJob:
 *   - DFI threshold check     → BYPASSED (life > score)
 *   - Quiet Hours check       → BYPASSED (2am notifications allowed)
 *   - Availability toggle and calendar → BYPASSED
 *   - Medical cooldown and group compatibility → NEVER bypassed (DonorCandidateFinder)
 *   - topK                    → 20 (strict limit, quality > quantity)
 *   - Radius                  → 10km (hyper-local first)
 *   - Ranking                 → Response Rate weighted (best responders first)
 */
class SosBloodRequestBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_DISTANCE_KM      = 10.0;   // Hyper-local: 10km for SOS
    private const DEFAULT_TOP_K        = 20;      // Strict cap — SMS bankruptcy prevention
    private const CANDIDATE_MULTIPLIER = 10;      // Candidate pool = topK × 10
    private const MAX_CANDIDATES       = 200;

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
        $candidates = $finder->find(
            $bloodRequest,
            radiusKm: self::MAX_DISTANCE_KM,
            limit: min(self::MAX_CANDIDATES, $this->topK * self::CANDIDATE_MULTIPLIER),
            respectAvailabilityToggle: false,
            respectCalendar: false,
        );

        if ($candidates->isEmpty()) {
            return;
        }

        $rates = $responseRates->forDonors($candidates->map(fn (DonorCandidate $c) => (int) $c->user->id)->all());

        $eligibility = app(EligibilityService::class);

        $ranked = $candidates->map(function (DonorCandidate $c) use ($rates, $now, $eligibility, $bloodRequest): array {
            $donorId = (int) $c->user->id;

            return [
                'donor_id'                 => $donorId,
                'distance_km'              => $c->distanceKm,
                // was hardcoded to 0, which wrote a wrong feature into donor_response_logs
                'days_since_last_donation' => $eligibility->daysSinceLastDonation($c->user, $bloodRequest->component_type, $now),
                'temporal_hour'            => (int) $now->hour,
                'is_weekend'               => (bool) $now->isWeekend(),
                'historical_response_rate' => $rates[$donorId],
                'is_identical_group'       => $c->isIdenticalGroup,
                // DFI is intentionally excluded for SOS (life > score)
                'rank_score'               => $this->sosRankScore($rates[$donorId], $c, (bool) $c->user->is_ready_now),
            ];
        })->all();

        usort($ranked, fn (array $a, array $b) => $b['rank_score'] <=> $a['rank_score']);
        $topK = array_slice($ranked, 0, max(1, $this->topK));

        // Reuse the existing DispatchEmergencyAlertsJob for actual notification delivery
        // (FCM + Telegram + DB notification + response log persistence)
        DispatchEmergencyAlertsJob::dispatch(
            bloodRequestId: $bloodRequest->id,
            rankedDonors: $topK
        )->afterCommit();
    }

    /**
     * SOS-specific ranking: Response Rate is primary signal.
     * Unknown distance is scored as config('matching.assumed_distance_km').
     */
    private function sosRankScore(float $responseRate, DonorCandidate $candidate, bool $isReadyNow): float
    {
        // Response Rate (0–1) × 200 makes it dominant over distance penalty
        $score = ($responseRate * 200) - (($candidate->distanceKm ?? (float) config('matching.assumed_distance_km', 10.0)) * 2);

        if (!$candidate->isIdenticalGroup) {
            $score -= (float) config('matching.compatible_group_penalty', 30);
        }

        if ($isReadyNow) {
            $score += 10;
        }

        return round($score, 2);
    }
}
