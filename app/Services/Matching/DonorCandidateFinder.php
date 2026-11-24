<?php

namespace App\Services\Matching;

use App\Models\BloodRequest;
use App\Models\DonorAvailability;
use App\Models\User;
use App\Services\EligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may be notified for a blood request. Shared by all three dispatch paths.
 *
 * Hard rules only (group compatibility, eligibility, opt-outs, distance). Ordering
 * here is "identical group first, then nearest, unknown distance last"; each caller
 * applies its own score.
 *
 * Distance is computed in PHP after a bounding-box prefilter. The old SQL
 * `acos(...)` returned NULL when the argument rounded just above 1, which silently
 * dropped donors at (almost) the same coordinates as the request, and it only ran
 * on MySQL.
 */
class DonorCandidateFinder
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const KM_PER_DEGREE_LAT = 111.045;

    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly BloodCompatibility $compatibility,
    ) {}

    /**
     * @param bool $respectAvailabilityToggle exclude donors who switched "available" off
     * @param bool $respectCalendar           apply the donor's availability calendar rules
     * @param array<int> $excludeDonorIds
     * @return Collection<int, DonorCandidate> identical group first, then nearest, at most $limit
     */
    public function find(
        BloodRequest $request,
        float $radiusKm,
        int $limit,
        bool $respectAvailabilityToggle = true,
        bool $respectCalendar = true,
        array $excludeDonorIds = [],
    ): Collection {
        $groups = $this->compatibility->donorGroupsFor($request->blood_group, $request->component_type);

        if ($groups === [] || $limit < 1) {
            return collect();
        }

        $query = User::query()
            ->where('is_donor', true)
            ->where('is_shadowbanned', false)
            ->whereIn('blood_group', $groups)
            ->when($request->requested_by !== null, fn (Builder $q) => $q->where('id', '!=', $request->requested_by))
            ->when($excludeDonorIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeDonorIds))
            ->where(fn (Builder $q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()));

        // Medical cooldown: never bypassed, not even for SOS.
        $this->eligibility->scopeEligible($query, $request->component_type);

        if ($respectAvailabilityToggle) {
            $query->where(fn (Builder $q) => $q->whereNull('is_available')->orWhere('is_available', true));
        }

        if ($respectCalendar) {
            $this->scopeCalendarAvailableNow($query);
        }

        $hasCoordinates = $request->latitude !== null && $request->longitude !== null;

        // Donors who never pinned a location have no coordinates. Dropping them whenever the
        // request has a map pin would make the pin (which the form encourages) find nobody
        // in a database where few donors share a location. So a request with coordinates gets
        // donors within the radius PLUS same-district donors with an unknown distance.
        $unlocated = fn () => (clone $query)
            ->where('district_id', $request->district_id)
            ->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->get(['id', 'blood_group'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'blood_group' => $u->blood_group, 'distance' => null]);

        $rows = $hasCoordinates
            ? $this->withinRadius(clone $query, (float) $request->latitude, (float) $request->longitude, $radiusKm)->concat($unlocated())
            : (clone $query)->where('district_id', $request->district_id)
                ->get(['id', 'blood_group'])
                ->map(fn (User $u) => ['id' => (int) $u->id, 'blood_group' => $u->blood_group, 'distance' => null]);

        $recipient = $request->blood_group;

        $ordered = $rows
            ->map(fn (array $r) => $r + ['identical' => $this->compatibility->isIdentical($r['blood_group'], $recipient)])
            ->sort(function (array $a, array $b) {
                return [$a['identical'] ? 0 : 1, $a['distance'] ?? INF, $a['id']]
                    <=> [$b['identical'] ? 0 : 1, $b['distance'] ?? INF, $b['id']];
            })
            ->take($limit)
            ->values();

        if ($ordered->isEmpty()) {
            return collect();
        }

        $users = User::query()->whereIn('id', $ordered->pluck('id')->all())->get()->keyBy('id');

        return $ordered
            ->filter(fn (array $r) => $users->has($r['id']))
            ->map(fn (array $r) => new DonorCandidate(
                $users->get($r['id']),
                $r['distance'] === null ? null : round($r['distance'], 2),
                $r['identical'],
            ))
            ->values();
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        // asin(sqrt(a)) form with a clamp: stable for identical and nearly identical points.
        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt(max(0.0, $a))));
    }

    /**
     * @return Collection<int, array{id:int, blood_group:mixed, distance:float}>
     */
    private function withinRadius(Builder $query, float $lat, float $lng, float $radiusKm): Collection
    {
        $latDelta = $radiusKm / self::KM_PER_DEGREE_LAT;
        $lngDelta = $radiusKm / (self::KM_PER_DEGREE_LAT * max(0.01, abs(cos(deg2rad($lat)))));

        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['id', 'blood_group', 'latitude', 'longitude'])
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'blood_group' => $u->blood_group,
                'distance' => self::haversineKm($lat, $lng, (float) $u->latitude, (float) $u->longitude),
            ])
            ->filter(fn (array $r) => $r['distance'] <= $radiusKm)
            ->values();
    }

    /**
     * Donors without active calendar rules pass; donors with rules must have one covering now.
     * (Previously duplicated in DonorMatchingService and SmartBloodRequestBroadcastJob.)
     */
    private function scopeCalendarAvailableNow(Builder $query): void
    {
        $now = now()->setTimezone('Asia/Dhaka');
        $todayBit = DonorAvailability::bitForDay($now->dayOfWeek);
        $todayDate = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $inWindow = fn (Builder $tq) => $tq->whereNull('time_from')
            ->orWhereRaw('? BETWEEN time_from AND time_to', [$currentTime]);

        $query->where(function (Builder $q) use ($todayBit, $todayDate, $inWindow) {
            $q->whereDoesntHave('availabilities', fn (Builder $r) => $r->where('is_active', true))
                ->orWhereHas('availabilities', function (Builder $r) use ($todayBit, $todayDate, $inWindow) {
                    $r->where('is_active', true)->where(function (Builder $rq) use ($todayBit, $todayDate, $inWindow) {
                        $rq->where(fn (Builder $sq) => $sq->where('type', 'weekly')
                            ->whereRaw('(weekdays_bitmask & ?) > 0', [$todayBit])
                            ->where($inWindow))
                            ->orWhere(fn (Builder $sq) => $sq->where('type', 'specific_date')
                                ->where('specific_date', $todayDate)
                                ->where($inWindow))
                            ->orWhere(fn (Builder $sq) => $sq->where('type', 'date_range')
                                ->where('date_from', '<=', $todayDate)
                                ->where('date_to', '>=', $todayDate)
                                ->where($inWindow));
                    });
                });
        });
    }
}
