<?php

namespace App\Services\Matching;

use App\Models\DonorResponseLog;
use App\Models\User;

/**
 * A donor's answer history, summarised for the donor to see.
 *
 * `score` is exactly the smoothed response rate the dispatch ranking uses
 * (ResponseRateService), so what a donor is shown is what the system acts on.
 * It measures how often they say yes when asked. It deliberately does NOT use
 * "did they actually donate afterwards": that depends on the requester remembering
 * to verify, so it would blame donors for other people's forgetfulness.
 *
 * Outcomes: accepted, declined, ignored count as answers; pending (not known yet)
 * and moot (settled by someone else first) do not count either way.
 */
class DonorReliabilityService
{
    public const LEVEL_NEW = 'new';
    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';

    public function __construct(private readonly ResponseRateService $responseRates) {}

    /**
     * @return array{
     *   notified:int, accepted:int, declined:int, ignored:int, pending:int, moot:int,
     *   answered:int, score:int, level:string, confidence:string,
     *   median_response_minutes:?int, answered_by_choice_rate:?float
     * }
     */
    public function forDonor(User|int $donor): array
    {
        $donorId = $donor instanceof User ? (int) $donor->id : $donor;

        $counts = DonorResponseLog::query()
            ->where('donor_id', $donorId)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        $accepted = $counts['accepted'] ?? 0;
        $declined = $counts['declined'] ?? 0;
        $ignored = $counts['ignored'] ?? 0;
        $answered = $accepted + $declined + $ignored;

        return [
            'notified' => (int) $counts->sum(),
            'accepted' => $accepted,
            'declined' => $declined,
            'ignored' => $ignored,
            'pending' => $counts['pending'] ?? 0,
            'moot' => $counts['moot'] ?? 0,
            'answered' => $answered,
            'score' => (int) round($this->responseRates->forDonors([$donorId])[$donorId] * 100),
            'level' => $this->level($answered, $accepted),
            'confidence' => $this->confidence($answered),
            'median_response_minutes' => $this->medianResponseMinutes($donorId),
            // Of all answers, how many were a real reply (yes or no) rather than silence.
            'answered_by_choice_rate' => $answered > 0 ? round(($accepted + $declined) / $answered, 4) : null,
        ];
    }

    private function level(int $answered, int $accepted): string
    {
        if ($answered < (int) config('matching.reliability.min_answers', 5)) {
            return self::LEVEL_NEW;
        }

        $rate = $this->responseRates->smooth($accepted, $answered);

        return match (true) {
            $rate >= (float) config('matching.reliability.high_rate', 0.7) => self::LEVEL_HIGH,
            $rate >= (float) config('matching.reliability.medium_rate', 0.4) => self::LEVEL_MEDIUM,
            default => self::LEVEL_LOW,
        };
    }

    private function confidence(int $answered): string
    {
        return match (true) {
            $answered >= (int) config('matching.reliability.high_confidence_answers', 15) => 'high',
            $answered >= (int) config('matching.reliability.min_answers', 5) => 'medium',
            default => 'low',
        };
    }

    /** Median minutes from notification to the first reply, over replies only (yes or no). */
    private function medianResponseMinutes(int $donorId): ?int
    {
        $minutes = DonorResponseLog::query()
            ->where('donor_id', $donorId)
            ->whereIn('status', ['accepted', 'declined'])
            ->whereNotNull('response_time_minutes')
            ->orderBy('response_time_minutes')
            ->pluck('response_time_minutes')
            ->map(fn ($m) => (int) $m)
            ->values();

        if ($minutes->isEmpty()) {
            return null;
        }

        $n = $minutes->count();
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $minutes[$mid] : (int) round(($minutes[$mid - 1] + $minutes[$mid]) / 2);
    }
}
