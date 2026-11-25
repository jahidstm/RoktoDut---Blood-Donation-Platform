<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes SYNTHETIC blood requests (is_demo = true) so the analytics dashboard has
 * something to show before real demand data exists.
 *
 * Everything below is an assumption chosen by the author, not a measured Bangladeshi
 * statistic. Charts built on this data demonstrate the pipeline; they say nothing about
 * the real world, and the dashboard says so. The generator is seeded, so the same seed
 * gives the same data.
 */
class DemoDemandGenerator
{
    /** Assumed mix of requested groups. */
    private const GROUP_WEIGHTS = [
        'O+' => 0.30, 'B+' => 0.27, 'A+' => 0.22, 'AB+' => 0.09,
        'O-' => 0.04, 'B-' => 0.04, 'A-' => 0.03, 'AB-' => 0.01,
    ];

    private const URGENCY_WEIGHTS = ['normal' => 0.55, 'urgent' => 0.30, 'emergency' => 0.15];

    private const UNIT_WEIGHTS = [1 => 0.50, 2 => 0.30, 3 => 0.12, 4 => 0.08];

    /** Median hours to get a donor, by urgency (assumed). */
    private const MEDIAN_HOURS = ['normal' => 20.0, 'urgent' => 8.0, 'emergency' => 4.0];

    /** Weekday weights, Monday first (assumed: slightly busier early in the week). */
    private const WEEKDAY_WEIGHTS = [1.15, 1.10, 1.0, 1.0, 0.8, 0.85, 1.0];

    /** Requests younger than this are still open rather than decided. */
    private const OPEN_FOR_HOURS = 36;

    /** @return array{requests: int, responses: int, logs: int} */
    public function generate(int $count = 600, int $months = 6, int $seed = 42, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        mt_srand($seed);

        $districts = $this->districtPool();
        $donorIds = DB::table('users')->where('is_donor', true)->limit(2000)->pluck('id')->all();
        $anyUser = $donorIds[0] ?? DB::table('users')->value('id');
        if ($anyUser === null) {
            throw new RuntimeException('Create at least one user first: fulfilled demo requests need a donor to point at.');
        }

        $from = $now->subMonths($months)->startOfDay();
        $days = max(1, (int) $from->diffInDays($now));
        $dayWeights = $this->dayWeights($from, $days);

        $made = ['requests' => 0, 'responses' => 0, 'logs' => 0];

        DB::transaction(function () use ($count, $now, $from, $dayWeights, $districts, $donorIds, $anyUser, &$made) {
            for ($i = 0; $i < $count; $i++) {
                $created = $from->addDays($this->pick($dayWeights))
                    ->setTime(mt_rand(6, 23), mt_rand(0, 59), mt_rand(0, 59));
                if ($created->greaterThan($now)) {
                    $created = $now->subMinutes(mt_rand(5, 600));
                }

                $group = $this->pick(self::GROUP_WEIGHTS);
                $urgency = $this->pick(self::URGENCY_WEIGHTS);
                $units = $this->pick(self::UNIT_WEIGHTS);
                $place = $districts[$this->pick(array_column($districts, 'weight'))];

                [$status, $fulfilledAt] = $this->outcome($group, $urgency, $created, $now);

                $requestId = DB::table('blood_requests')->insertGetId([
                    'requested_by' => null,
                    'blood_group' => $group,
                    'component_type' => 'whole_blood',
                    'units_needed' => $units,
                    'bags_needed' => $units,
                    'division_id' => $place['division_id'],
                    'district_id' => $place['district_id'],
                    'upazila_id' => $place['upazila_id'],
                    'contact_number' => 'DEMO',
                    'urgency' => $urgency,
                    'needed_at' => $created->addHours(mt_rand(2, 48)),
                    'status' => $status,
                    'notes' => 'Synthetic demo request',
                    'is_demo' => true,
                    'created_at' => $created,
                    'updated_at' => $fulfilledAt ?? $created,
                ]);
                $made['requests']++;

                if ($fulfilledAt) {
                    DB::table('blood_request_responses')->insert([
                        'blood_request_id' => $requestId,
                        'user_id' => $donorIds ? $donorIds[mt_rand(0, count($donorIds) - 1)] : $anyUser,
                        'status' => 'accepted',
                        'fulfilled_at' => $fulfilledAt,
                        'created_at' => $created,
                        'updated_at' => $fulfilledAt,
                    ]);
                    $made['responses']++;
                }

                if ($donorIds) {
                    $made['logs'] += $this->notifyDonors($requestId, $created, $donorIds, $status === 'fulfilled');
                }
            }
        });

        return $made;
    }

    /** Deletes only synthetic rows. Responses and logs go with their request (FK cascade). */
    public function purge(): int
    {
        return DB::table('blood_requests')->where('is_demo', true)->delete();
    }

    /** @return array{0: string, 1: ?CarbonImmutable} */
    private function outcome(string $group, string $urgency, CarbonImmutable $created, CarbonImmutable $now): array
    {
        $rare = in_array($group, ['O-', 'B-', 'A-', 'AB-', 'AB+'], true);

        if ($created->diffInHours($now, true) < self::OPEN_FOR_HOURS) {
            return [mt_rand(0, 1) ? 'pending' : 'approved', null];
        }

        $p = 0.80;
        $p *= $rare ? 0.80 : 1.0;
        $p *= $urgency === 'emergency' ? 1.05 : 1.0;
        $roll = mt_rand() / mt_getrandmax();

        if ($roll < min(0.95, $p)) {
            // Log-normal wait: most are quick, a few take days.
            $hours = self::MEDIAN_HOURS[$urgency] * ($rare ? 1.6 : 1.0)
                * exp(0.8 * $this->gauss());
            $at = $created->addSeconds((int) (max(0.2, $hours) * 3600));

            return $at->greaterThan($now) ? ['approved', null] : ['fulfilled', $at];
        }

        return [$roll < min(0.95, $p) + 0.05 ? 'rejected' : 'approved', null];
    }

    /** Notified donors and their answers (assumed rates: 18% accept, 12% decline, rest ignore). */
    private function notifyDonors(int $requestId, CarbonImmutable $created, array $donorIds, bool $fulfilled): int
    {
        $n = mt_rand(6, 14);
        $rows = [];

        foreach ((array) array_rand(array_flip($donorIds), min($n, count($donorIds))) as $donorId) {
            $roll = mt_rand() / mt_getrandmax();
            $status = $roll < 0.18 ? 'accepted' : ($roll < 0.30 ? 'declined' : 'ignored');
            $minutes = $status === 'ignored' ? null : (int) max(1, 25 * exp(0.9 * $this->gauss()));

            $rows[] = [
                'request_id' => $requestId,
                'donor_id' => $donorId,
                'notified_at' => $created,
                'responded_at' => $minutes ? $created->addMinutes($minutes) : null,
                'status' => $status,
                'response_time_minutes' => $minutes,
                'distance_km' => null,
                'days_since_last_donation' => mt_rand(30, 400),
                'temporal_hour' => (int) $created->format('G'),
                'is_weekend' => $created->isWeekend(),
                'historical_response_rate' => 0.3,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        DB::table('donor_response_logs')->insert($rows);

        return count($rows);
    }

    /** Districts with a usable upazila, weighted by a Zipf curve with Dhaka and Chattogram first. */
    private function districtPool(): array
    {
        $rows = DB::table('districts')
            ->join('upazilas', 'upazilas.district_id', '=', 'districts.id')
            ->selectRaw('districts.id as district_id, districts.division_id, districts.name, MIN(upazilas.id) as upazila_id')
            ->groupBy('districts.id', 'districts.division_id', 'districts.name')
            ->orderBy('districts.id')
            ->get();

        if ($rows->isEmpty()) {
            throw new RuntimeException('No districts with upazilas found. Run the location seeder first.');
        }

        $big = ['ঢাকা' => 0, 'চট্টগ্রাম' => 1];
        $ordered = $rows->sortBy(fn ($r) => $big[$r->name] ?? 2 + $r->district_id)->values();

        return $ordered->map(fn ($r, $rank) => [
            'district_id' => (int) $r->district_id,
            'division_id' => (int) $r->division_id,
            'upazila_id' => (int) $r->upazila_id,
            'weight' => 1 / pow($rank + 1, 0.9),
        ])->all();
    }

    /** Requests per day grow gently over the period (assumed platform growth) and follow the weekday weights. */
    private function dayWeights(CarbonImmutable $from, int $days): array
    {
        $weights = [];
        for ($d = 0; $d < $days; $d++) {
            $weekday = (int) $from->addDays($d)->format('N') - 1;
            $weights[$d] = (1 + 1.5 * $d / $days) * self::WEEKDAY_WEIGHTS[$weekday];
        }

        return $weights;
    }

    /** Weighted choice: returns the array KEY. */
    private function pick(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $total;

        foreach ($weights as $key => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Standard normal draw (Box-Muller). */
    private function gauss(): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
