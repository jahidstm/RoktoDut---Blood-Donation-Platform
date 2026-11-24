<?php

namespace Tests\Feature\Matching;

use App\Models\BloodRequest;
use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class MatchingTestCase extends TestCase
{
    use RefreshDatabase;

    /** Dhaka Medical College Hospital, roughly. */
    protected const LAT = 23.7257;
    protected const LNG = 90.3977;

    protected District $district;
    protected Upazila $upazila;
    protected User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 on a Wednesday in Dhaka: outside quiet hours, a fixed weekday for calendar tests.
        $this->travelTo(now('Asia/Dhaka')->next('Wednesday')->setTime(10, 0)->utc());

        $division = Division::create(['name' => 'Dhaka']);
        $this->district = District::create(['division_id' => $division->id, 'name' => 'Dhaka']);
        $this->upazila = Upazila::create(['district_id' => $this->district->id, 'name' => 'Dhaka']);
        $this->requester = User::factory()->create(['is_donor' => false]);
    }

    /** An eligible, available donor at the hospital; override anything. */
    protected function donor(string $group, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'is_donor' => true,
            'blood_group' => $group,
            'gender' => 'male',
            'is_available' => true,
            'is_ready_now' => false,
            'is_shadowbanned' => false,
            'cooldown_until' => null,
            'last_donated_at' => null,
            'last_whole_blood_donated_at' => null,
            'last_plasma_donated_at' => null,
            'last_platelet_donated_at' => null,
            'dfi_score' => 0,
            'district_id' => $this->district->id,
            'upazila_id' => $this->upazila->id,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }

    /** Coordinates roughly $km north of the hospital. */
    protected function north(float $km): array
    {
        return ['latitude' => self::LAT + $km / 111.045, 'longitude' => self::LNG];
    }

    protected function request(string $group, array $overrides = []): BloodRequest
    {
        return BloodRequest::create(array_merge([
            'requested_by' => $this->requester->id,
            'patient_name' => 'Test Patient',
            'blood_group' => $group,
            'component_type' => 'whole_blood',
            'division_id' => $this->district->division_id,
            'district_id' => $this->district->id,
            'upazila_id' => $this->upazila->id,
            'status' => 'pending',
            'contact_number' => '01711111111',
            'urgency' => 'urgent',
            'needed_at' => now()->addHours(6),
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }
}
