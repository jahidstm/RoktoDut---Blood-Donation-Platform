<?php

namespace App\Services\Matching;

use App\Models\DonorResponseLog;

/**
 * How often a donor says yes when notified, smoothed toward a prior.
 *
 * Replaces five copies of the same query that (a) counted unanswered "pending"
 * rows in the denominator, which understated everyone's rate, and (b) gave every
 * new donor 0.0, which put them last in every ranking and, in quiet hours,
 * excluded them entirely. With a prior, a new donor starts at the prior mean and
 * moves toward their real rate as answers accumulate.
 */
class ResponseRateService
{
    /** Outcomes that count as an answer (pending is "not yet known"). */
    private const ANSWERED = ['accepted', 'declined', 'ignored'];

    /**
     * @param array<int> $donorIds
     * @return array<int, float> donor id => smoothed rate in [0, 1]; every requested id is present
     */
    public function forDonors(array $donorIds): array
    {
        $donorIds = array_values(array_unique(array_map('intval', $donorIds)));

        if ($donorIds === []) {
            return [];
        }

        $stats = DonorResponseLog::query()
            ->whereIn('donor_id', $donorIds)
            ->whereIn('status', self::ANSWERED)
            ->selectRaw('donor_id, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS accepted_count, COUNT(*) AS answered_count', ['accepted'])
            ->groupBy('donor_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->donor_id);

        $rates = [];
        foreach ($donorIds as $id) {
            $row = $stats->get($id);
            $rates[$id] = $this->smooth((int) ($row->accepted_count ?? 0), (int) ($row->answered_count ?? 0));
        }

        return $rates;
    }

    public function smooth(int $accepted, int $answered): float
    {
        $mean = (float) config('matching.response_rate_prior.mean', 0.3);
        $strength = (float) config('matching.response_rate_prior.strength', 5);

        return round(($accepted + $strength * $mean) / ($answered + $strength), 4);
    }
}
