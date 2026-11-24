<?php

namespace Tests\Feature;

use App\Enums\BloodGroup;
use App\Models\BloodRequest;
use App\Models\District;
use App\Models\Division;
use App\Models\DonorResponseLog;
use App\Models\Upazila;
use App\Models\User;
use App\Services\DonorResponseLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * donor_response_logs is the training table for the donor-response model. Before this
 * was fixed nothing ever moved a row out of "pending", so there were no labels at all.
 */
class DonorResponseLoggingTest extends TestCase
{
    use RefreshDatabase;

    private BloodRequest $bloodRequest;
    private User $requester;
    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Dhaka']);
        $district = District::create(['division_id' => $division->id, 'name' => 'Dhaka']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Dhaka']);

        $this->requester = User::factory()->create();
        $this->donor = User::factory()->create([
            'blood_group' => BloodGroup::A_POS->value,
            'last_donated_at' => null,
            'last_whole_blood_donated_at' => null,
            'cooldown_until' => null,
        ]);

        $this->bloodRequest = BloodRequest::create([
            'requested_by' => $this->requester->id,
            'patient_name' => 'Test Patient',
            'blood_group' => BloodGroup::A_POS->value,
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'status' => 'pending',
            'contact_number' => '01711111111',
            'location_text' => 'Dhaka Medical',
            'urgency' => 'urgent',
            'needed_at' => now()->addHours(3),
        ]);
    }

    private function notify(User $donor, ?BloodRequest $request = null, $notifiedAt = null): DonorResponseLog
    {
        return DonorResponseLog::create([
            'request_id' => ($request ?? $this->bloodRequest)->id,
            'donor_id' => $donor->id,
            'notified_at' => $notifiedAt ?? now()->subMinutes(30),
            'status' => 'pending',
            'distance_km' => 3.2,
            'days_since_last_donation' => 200,
            'temporal_hour' => 14,
            'is_weekend' => false,
            'historical_response_rate' => 0.0,
        ]);
    }

    public function test_donor_saying_yes_marks_log_accepted_with_response_time(): void
    {
        $log = $this->notify($this->donor);

        $this->actingAs($this->donor)
            ->post(route('requests.respond', $this->bloodRequest), ['status' => 'pending'])
            ->assertSessionHasNoErrors();

        $log->refresh();
        $this->assertSame('accepted', $log->status);
        $this->assertNotNull($log->responded_at);
        $this->assertEqualsWithDelta(30, $log->response_time_minutes, 1);
    }

    public function test_donor_declining_marks_log_declined(): void
    {
        $log = $this->notify($this->donor);

        $this->actingAs($this->donor)
            ->post(route('requests.respond', $this->bloodRequest), ['status' => 'declined']);

        $this->assertSame('declined', $log->fresh()->status);
    }

    public function test_changing_mind_keeps_the_first_response_time(): void
    {
        $log = $this->notify($this->donor);
        $logger = app(DonorResponseLogger::class);

        $logger->recordResponse($this->bloodRequest->id, $this->donor->id, 'pending');
        $firstRespondedAt = $log->fresh()->responded_at;
        $firstMinutes = $log->fresh()->response_time_minutes;

        $this->travel(2)->hours();
        $logger->recordResponse($this->bloodRequest->id, $this->donor->id, 'withdrawn');

        $log->refresh();
        $this->assertSame('declined', $log->status);
        $this->assertEquals($firstRespondedAt, $log->responded_at);
        $this->assertSame($firstMinutes, $log->response_time_minutes);
    }

    public function test_requester_approving_a_donor_does_not_touch_the_donor_log(): void
    {
        $log = $this->notify($this->donor);

        $this->assertFalse(
            app(DonorResponseLogger::class)->recordResponse($this->bloodRequest->id, $this->donor->id, 'accepted')
        );
        $this->assertSame('pending', $log->fresh()->status);
    }

    public function test_a_donor_who_was_never_notified_gets_no_log_row(): void
    {
        // Browsing donors would bias the data toward people who respond anyway.
        $this->actingAs($this->donor)
            ->post(route('requests.respond', $this->bloodRequest), ['status' => 'pending']);

        $this->assertSame(0, DonorResponseLog::count());
    }

    public function test_expiring_a_request_marks_unanswered_donors_ignored(): void
    {
        $answered = $this->notify($this->donor);
        $silent = $this->notify(User::factory()->create());
        app(DonorResponseLogger::class)->recordResponse($this->bloodRequest->id, $this->donor->id, 'pending');

        $this->bloodRequest->update(['needed_at' => now()->subHours(7)]);
        $this->artisan('requests:expire')->assertSuccessful();

        $this->assertSame('accepted', $answered->fresh()->status);
        $this->assertSame('ignored', $silent->fresh()->status);
    }

    public function test_stale_pending_logs_become_ignored_even_if_no_request_expires(): void
    {
        $recent = $this->notify($this->donor, null, now()->subHours(2));
        $old = $this->notify(User::factory()->create(), null, now()->subHours(25));

        $this->artisan('requests:expire')->assertSuccessful();

        $this->assertSame('pending', $recent->fresh()->status);
        $this->assertSame('ignored', $old->fresh()->status);
    }
}
