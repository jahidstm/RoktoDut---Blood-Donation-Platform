<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\BloodRequestResponse;
use App\Models\User;
use App\Notifications\BloodRequestCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class CancelRequestTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function bloodRequest(User $owner): BloodRequest
    {
        $this->seedLocationsAndHospitals();
        $district = DB::table('districts')->first();

        return BloodRequest::create([
            'requested_by' => $owner->id, 'patient_name' => 'Patient', 'blood_group' => 'A+',
            'component_type' => 'whole_blood', 'bags_needed' => 1,
            'division_id' => $district->division_id, 'district_id' => $district->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $district->id)->value('id'),
            'contact_number' => '01700000001', 'urgency' => 'normal', 'status' => 'pending',
            'needed_at' => now()->addDay(),
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_onboarded' => true]);
    }

    private function response(BloodRequest $request, User $donor, array $attributes = []): BloodRequestResponse
    {
        return BloodRequestResponse::create($attributes + [
            'blood_request_id' => $request->id, 'user_id' => $donor->id, 'status' => 'accepted',
        ]);
    }

    public function test_the_owner_can_cancel_and_responding_donors_are_told(): void
    {
        $owner = $this->user('recipient');
        $donor = $this->user('donor');
        $request = $this->bloodRequest($owner);
        $this->response($request, $donor);
        Notification::fake();

        $this->actingAs($owner)->post(route('requests.cancel', $request))->assertSessionHas('success');

        $this->assertSame('cancelled', $request->fresh()->status);
        Notification::assertSentTo($donor, BloodRequestCancelledNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.cancel']);
    }

    public function test_nobody_else_can_cancel(): void
    {
        $request = $this->bloodRequest($this->user('recipient'));

        $this->actingAs($this->user('recipient'))->post(route('requests.cancel', $request))->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_claimed_donation_blocks_cancelling(): void
    {
        $owner = $this->user('recipient');
        $request = $this->bloodRequest($owner);
        $this->response($request, $this->user('donor'), ['verification_status' => 'claimed']);

        $this->actingAs($owner)->post(route('requests.cancel', $request))->assertSessionHas('error');
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_cancelled_request_takes_no_offers_and_cannot_be_revived(): void
    {
        $owner = $this->user('recipient');
        $request = $this->bloodRequest($owner);
        $request->update(['status' => 'cancelled', 'needed_at' => now()->subDay()]);

        $this->actingAs($this->user('donor'))
            ->post(route('requests.respond', $request), ['status' => 'pending'])
            ->assertSessionHas('error');
        $this->actingAs($owner)
            ->post(route('requests.renew', $request), ['needed_at' => now()->addDay()->toDateTimeString(), 'urgency' => 'normal'])
            ->assertSessionHas('error');

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertDatabaseCount('blood_request_responses', 0);
    }

    public function test_old_requests_can_now_expire(): void
    {
        $request = $this->bloodRequest($this->user('recipient'));
        $request->update(['needed_at' => now()->subDay()]);

        $this->artisan('requests:expire')->assertSuccessful();

        $this->assertSame('expired', $request->fresh()->status);
        $column = DB::selectOne("select sql from sqlite_master where name = 'blood_requests'");
        $this->assertStringContainsString("'expired'", $column->sql);
    }
}
