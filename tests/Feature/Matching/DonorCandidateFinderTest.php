<?php

namespace Tests\Feature\Matching;

use App\Models\DonorAvailability;
use App\Services\Matching\DonorCandidate;
use App\Services\Matching\DonorCandidateFinder;

class DonorCandidateFinderTest extends MatchingTestCase
{
    private function ids($candidates): array
    {
        return $candidates->map(fn (DonorCandidate $c) => $c->user->id)->all();
    }

    private function find($request, array $opts = [])
    {
        return app(DonorCandidateFinder::class)->find(
            $request,
            radiusKm: $opts['radius'] ?? 20.0,
            limit: $opts['limit'] ?? 50,
            respectAvailabilityToggle: $opts['toggle'] ?? true,
            respectCalendar: $opts['calendar'] ?? true,
        );
    }

    public function test_donor_at_the_exact_request_location_is_found(): void
    {
        // Regression: SQL acos() returned NULL here and the donor silently disappeared.
        $here = $this->donor('A+');

        $found = $this->find($this->request('A+'));

        $this->assertSame([$here->id], $this->ids($found));
        $this->assertSame(0.0, $found->first()->distanceKm);
    }

    public function test_hard_rules_exclude_the_right_people(): void
    {
        $ok = $this->donor('A+', $this->north(3));
        $this->donor('A+', ['is_shadowbanned' => true]);
        $this->donor('A+', ['is_donor' => false]);
        $this->donor('A+', ['is_available' => false]);
        $this->donor('A+', ['cooldown_until' => now()->addDays(3)]);
        $this->donor('A+', ['last_whole_blood_donated_at' => now()->subDays(30)->toDateString()]);
        $this->donor('A+', $this->north(25));            // outside 20 km
        $this->donor('B+');                               // wrong group
        $request = $this->request('A+');
        $this->requester->update(['is_donor' => true, 'blood_group' => 'A+', 'is_available' => true, 'latitude' => self::LAT, 'longitude' => self::LNG]);

        $this->assertSame([$ok->id], $this->ids($this->find($request)));
    }

    public function test_whole_blood_never_includes_other_groups(): void
    {
        $this->donor('O-');
        $this->donor('O+');
        $same = $this->donor('A+', $this->north(10));

        $this->assertSame([$same->id], $this->ids($this->find($this->request('A+'))));
    }

    public function test_packed_rbc_puts_identical_group_first_even_when_farther(): void
    {
        $nearUniversal = $this->donor('O-', $this->north(1));
        $farIdentical = $this->donor('A+', $this->north(15));
        $this->donor('B+', $this->north(1));              // not compatible with A+

        $found = $this->find($this->request('A+', ['component_type' => 'packed_rbc']));

        $this->assertSame([$farIdentical->id, $nearUniversal->id], $this->ids($found));
        $this->assertTrue($found[0]->isIdenticalGroup);
        $this->assertFalse($found[1]->isIdenticalGroup);

        // with room for one donor only, the identical one wins
        $one = $this->find($this->request('A+', ['component_type' => 'packed_rbc']), ['limit' => 1]);
        $this->assertSame([$farIdentical->id], $this->ids($one));
    }

    public function test_without_coordinates_it_falls_back_to_the_district_and_distance_is_unknown(): void
    {
        $inDistrict = $this->donor('A+', ['latitude' => null, 'longitude' => null]);

        $found = $this->find($this->request('A+', ['latitude' => null, 'longitude' => null]));

        $this->assertSame([$inDistrict->id], $this->ids($found));
        $this->assertNull($found->first()->distanceKm, 'unknown distance is null, not 9999');
    }

    public function test_availability_flags_can_be_bypassed_but_cooldown_cannot(): void
    {
        $off = $this->donor('A+', ['is_available' => false]);
        $this->donor('A+', ['last_whole_blood_donated_at' => now()->subDays(10)->toDateString()]);

        $found = $this->find($this->request('A+'), ['toggle' => false, 'calendar' => false]);

        $this->assertSame([$off->id], $this->ids($found));
    }

    public function test_calendar_rules_are_respected_unless_bypassed(): void
    {
        $busyToday = $this->donor('A+');
        DonorAvailability::create([
            'user_id' => $busyToday->id,
            'type' => 'weekly',
            // every day except today (Wednesday)
            'weekdays_bitmask' => 127 & ~DonorAvailability::bitForDay(now('Asia/Dhaka')->dayOfWeek),
            'is_active' => true,
        ]);
        $freeToday = $this->donor('A+');
        DonorAvailability::create([
            'user_id' => $freeToday->id,
            'type' => 'weekly',
            'weekdays_bitmask' => DonorAvailability::bitForDay(now('Asia/Dhaka')->dayOfWeek),
            'is_active' => true,
        ]);
        $noRules = $this->donor('A+');

        $request = $this->request('A+');

        $this->assertEqualsCanonicalizing([$freeToday->id, $noRules->id], $this->ids($this->find($request)));
        $this->assertCount(3, $this->find($request, ['calendar' => false]));
    }

    public function test_haversine_is_correct_and_stable(): void
    {
        $this->assertSame(0.0, DonorCandidateFinder::haversineKm(23.7, 90.4, 23.7, 90.4));
        // Dhaka (23.8103, 90.4125) to Chattogram (22.3569, 91.7832) is about 216 km.
        $this->assertEqualsWithDelta(216, DonorCandidateFinder::haversineKm(23.8103, 90.4125, 22.3569, 91.7832), 3);
    }
}
