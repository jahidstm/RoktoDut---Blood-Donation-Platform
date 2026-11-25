<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Descriptive statistics over blood requests. Plain counting and grouping, no model:
 * this is "what happened", not a forecast (that is Feature 8).
 *
 * Aggregation happens in PHP over a narrow column selection so the same code runs on
 * MySQL and SQLite (the old dashboard used MySQL-only DATE_FORMAT).
 *
 * Three honesty rules are built in:
 *  - requests flagged is_demo are synthetic; `source` keeps them apart from real ones;
 *  - the fulfilment rate only counts requests old enough to have had a chance
 *    (config analytics.mature_after_days), because a request made an hour ago has not
 *    "failed" yet. This is the same right-censoring problem as the donor response labels;
 *  - small samples are reported with their n, never hidden.
 */
class DemandAnalytics
{
    public const SOURCE_REAL = 'real';
    public const SOURCE_DEMO = 'demo';
    public const SOURCE_ALL = 'all';

    /** Statuses that count as demand. nlp_pending is a draft that was never confirmed. */
    private const COUNTED_STATUSES = ['pending', 'approved', 'fulfilled', 'rejected'];

    public function countRequests(string $source): int
    {
        return $this->requestQuery($source)->count();
    }

    /** @return array<string, mixed> */
    public function compute(string $source = self::SOURCE_REAL, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subMonths((int) config('analytics.window_months', 12))->startOfDay();
        $matureCutoff = $now->subDays((int) config('analytics.mature_after_days', 7));

        $requests = $this->requestQuery($source)
            ->where('created_at', '>=', $windowStart)
            ->select(['id', 'blood_group', 'units_needed', 'urgency', 'status', 'division_id', 'district_id', 'created_at'])
            ->get()
            ->map(function ($row) {
                $row->created = CarbonImmutable::parse($row->created_at);
                $row->units = max(1, (int) ($row->units_needed ?? 1));

                return $row;
            });

        $firstFulfilled = $this->firstFulfilledAt($requests->pluck('id')->all());

        $requests->each(function ($r) use ($firstFulfilled, $matureCutoff) {
            $at = $firstFulfilled[$r->id] ?? null;
            $r->fulfilled_at = $at ? CarbonImmutable::parse($at) : null;
            $r->hours_to_fulfil = $r->fulfilled_at
                ? max(0.0, $r->created->diffInSeconds($r->fulfilled_at, true) / 3600)
                : null;
            $r->is_fulfilled = $r->status === 'fulfilled';
            $r->is_mature = $r->created->lessThanOrEqualTo($matureCutoff);
        });

        $mature = $requests->where('is_mature', true);
        $hours = $requests->whereNotNull('hours_to_fulfil')->pluck('hours_to_fulfil')->sort()->values();

        return [
            'source' => $source,
            'window' => ['from' => $windowStart->toDateString(), 'to' => $now->toDateString()],
            'kpi' => [
                'requests' => $requests->count(),
                'bags' => (int) $requests->sum('units'),
                'fulfilled' => $requests->where('is_fulfilled', true)->count(),
                'mature_requests' => $mature->count(),
                'fulfilment_rate' => $this->rate($mature->where('is_fulfilled', true)->count(), $mature->count()),
                'median_hours_to_fulfil' => $this->percentile($hours, 0.5),
                'p90_hours_to_fulfil' => $this->percentile($hours, 0.9),
                'timed_requests' => $hours->count(),
            ],
            'by_blood_group' => $this->byBloodGroup($requests, $mature),
            'by_division' => $this->byPlace($requests, 'division_id', 'divisions'),
            'by_district' => $this->byPlace($requests, 'district_id', 'districts', 10),
            'weekly' => $this->weekly($requests, $now),
            'by_urgency' => $this->byUrgency($requests),
            'donor_response' => $this->donorResponse($requests->pluck('id')->all()),
        ];
    }

    private function requestQuery(string $source)
    {
        $q = DB::table('blood_requests')
            ->whereIn('status', self::COUNTED_STATUSES)
            ->where(fn ($w) => $w->where('is_hidden', false)->orWhereNull('is_hidden'));

        return match ($source) {
            self::SOURCE_DEMO => $q->where('is_demo', true),
            self::SOURCE_ALL => $q,
            default => $q->where('is_demo', false),
        };
    }

    /** @param list<int> $ids  @return array<int, string> request id => earliest fulfilled_at */
    private function firstFulfilledAt(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('blood_request_responses')
                ->whereIn('blood_request_id', $chunk)
                ->whereNotNull('fulfilled_at')
                ->selectRaw('blood_request_id, MIN(fulfilled_at) as first_at')
                ->groupBy('blood_request_id')
                ->get();
            foreach ($rows as $row) {
                $out[$row->blood_request_id] = $row->first_at;
            }
        }

        return $out;
    }

    /**
     * Demand share next to donor share for each group. A group whose share of demand is
     * well above its share of donors is the one a recruitment drive should target.
     * Registered donors are a proxy for supply: it ignores who is eligible today.
     */
    private function byBloodGroup(Collection $requests, Collection $mature): array
    {
        $donors = DB::table('users')
            ->where('is_donor', true)
            ->whereNotNull('blood_group')
            ->selectRaw('blood_group, COUNT(*) as n')
            ->groupBy('blood_group')
            ->pluck('n', 'blood_group');

        $totalBags = max(1, (int) $requests->sum('units'));
        $totalDonors = max(1, (int) $donors->sum());

        $rows = [];
        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $group) {
            $mine = $requests->where('blood_group', $group);
            $matureMine = $mature->where('blood_group', $group);
            $bags = (int) $mine->sum('units');
            $donorCount = (int) ($donors[$group] ?? 0);

            $rows[] = [
                'group' => $group,
                'requests' => $mine->count(),
                'bags' => $bags,
                'demand_share' => round($bags / $totalBags, 4),
                'donors' => $donorCount,
                'donor_share' => round($donorCount / $totalDonors, 4),
                'mature_requests' => $matureMine->count(),
                'fulfilment_rate' => $this->rate($matureMine->where('is_fulfilled', true)->count(), $matureMine->count()),
            ];
        }

        return $rows;
    }

    private function byPlace(Collection $requests, string $column, string $table, ?int $limit = null): array
    {
        $names = DB::table($table)->pluck('name', 'id');

        $rows = $requests->groupBy($column)->map(fn ($group, $id) => [
            'id' => (int) $id,
            'name' => $names[$id] ?? '—',
            'requests' => $group->count(),
            'bags' => (int) $group->sum('units'),
        ])->sortByDesc('requests')->values();

        return ($limit ? $rows->take($limit) : $rows)->all();
    }

    /** Requests created and requests fulfilled per week, last N weeks, empty weeks included. */
    private function weekly(Collection $requests, CarbonImmutable $now): array
    {
        $weeks = (int) config('analytics.weeks_shown', 12);
        $thisWeek = $now->startOfWeek();
        $out = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $thisWeek->subWeeks($i);
            $end = $start->addWeek();
            $inWeek = $requests->filter(fn ($r) => $r->created >= $start && $r->created < $end);

            $out[] = [
                'week_start' => $start->toDateString(),
                'created' => $inWeek->count(),
                'fulfilled' => $inWeek->where('is_fulfilled', true)->count(),
            ];
        }

        return $out;
    }

    private function byUrgency(Collection $requests): array
    {
        $total = max(1, $requests->count());

        return $requests->groupBy('urgency')
            ->map(fn ($g, $level) => [
                'urgency' => (string) $level,
                'requests' => $g->count(),
                'share' => round($g->count() / $total, 4),
            ])->sortByDesc('requests')->values()->all();
    }

    /**
     * Of the donors who actually answered (accepted or declined) or let the 24h window
     * pass (ignored), how many accepted. Pending and moot rows are excluded: the first is
     * unfinished, the second ended because the request closed, not because of the donor.
     *
     * @param list<int> $ids
     */
    private function donorResponse(array $ids): array
    {
        $counts = ['accepted' => 0, 'declined' => 0, 'ignored' => 0];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('donor_response_logs')
                ->whereIn('request_id', $chunk)
                ->whereIn('status', array_keys($counts))
                ->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')
                ->get();
            foreach ($rows as $row) {
                $counts[$row->status] += (int) $row->n;
            }
        }

        $answered = array_sum($counts);

        return $counts + ['answered' => $answered, 'accept_rate' => $this->rate($counts['accepted'], $answered)];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }

    /** Nearest-rank percentile over an already sorted collection; null when empty. */
    private function percentile(Collection $sorted, float $p): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = max(0, (int) ceil($p * $sorted->count()) - 1);

        return round((float) $sorted[$index], 1);
    }
}
