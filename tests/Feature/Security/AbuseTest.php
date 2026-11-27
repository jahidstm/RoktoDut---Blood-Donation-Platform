<?php

namespace Tests\Feature\Security;

use App\Models\BloodRequest;
use App\Models\BloodRequestResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

/** Points farming, SOS spam and script injection found in the second audit round. */
class AbuseTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private object $district;
    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $this->district = DB::table('districts')->first();
        $this->upazilaId = DB::table('upazilas')->where('district_id', $this->district->id)->value('id');
    }

    private function user(array $over = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'donor', 'phone' => '017'.random_int(10000000, 99999999), 'points' => 0,
            'total_verified_donations' => 0, 'division_id' => $this->district->division_id,
            'district_id' => $this->district->id, 'upazila_id' => $this->upazilaId,
        ], $over));
    }

    private function bloodRequest(User $owner): BloodRequest
    {
        return BloodRequest::create([
            'requested_by' => $owner->id, 'blood_group' => 'O+', 'component_type' => 'whole_blood', 'bags_needed' => 2,
            'division_id' => $this->district->division_id, 'district_id' => $this->district->id,
            'upazila_id' => $this->upazilaId, 'contact_number' => '01555555555', 'urgency' => 'normal',
            'needed_at' => now()->addDays(3), 'status' => 'pending', 'patient_name' => 'p',
        ]);
    }

    private function response(BloodRequest $request, User $donor, string $status = 'accepted', string $verification = 'pending'): BloodRequestResponse
    {
        $r = BloodRequestResponse::create(['blood_request_id' => $request->id, 'user_id' => $donor->id, 'status' => $status]);
        $r->forceFill(['verification_status' => $verification])->save();

        return $r->fresh();
    }

    // ── Reward points ───────────────────────────────────────────────

    public function test_marking_a_request_fulfilled_rewards_only_donors_who_claimed(): void
    {
        $owner = $this->user();
        $request = $this->bloodRequest($owner);
        $claimed = $this->user();
        $silent = $this->user();
        $this->response($request, $claimed, verification: 'claimed');
        $this->response($request, $silent);

        $this->actingAs($owner)->post("/requests/{$request->id}/fulfill")->assertRedirect();

        $this->assertGreaterThan(0, $claimed->fresh()->points);
        $this->assertSame(0, (int) $silent->fresh()->points);
        $this->assertNull($silent->fresh()->last_donated_at, 'a donor who did not donate must not be put on cooldown');
    }

    public function test_the_reward_listener_is_registered_once(): void
    {
        $this->assertCount(1, \Illuminate\Support\Facades\Event::getRawListeners()[\App\Events\DonationCompleted::class]);
    }

    public function test_the_recipient_cannot_verify_a_donation_nobody_claimed(): void
    {
        $owner = $this->user();
        $donor = $this->user();
        $response = $this->response($this->bloodRequest($owner), $donor, status: 'pending');

        $this->actingAs($owner)->post("/responses/{$response->id}/recipient-verify", ['decision' => 'approve']);

        $this->assertSame(0, (int) $donor->fresh()->points);
        $this->assertSame('pending', $response->fresh()->verification_status);
    }

    public function test_a_claimed_donation_can_still_be_verified(): void
    {
        $owner = $this->user();
        $donor = $this->user();
        $response = $this->response($this->bloodRequest($owner), $donor, verification: 'claimed');

        $this->actingAs($owner)->post("/responses/{$response->id}/recipient-verify", ['decision' => 'approve']);

        $this->assertSame('verified', $response->fresh()->verification_status);
        $this->assertGreaterThan(0, $donor->fresh()->points);
    }

    public function test_a_reward_blocked_by_cooldown_does_not_leave_the_donation_verified(): void
    {
        $owner = $this->user();
        $donor = $this->user(['last_donated_at' => now()->subDays(10)]);
        $response = $this->response($this->bloodRequest($owner), $donor, verification: 'claimed');

        $this->actingAs($owner)->post("/responses/{$response->id}/recipient-verify", ['decision' => 'approve'])->assertRedirect();

        $this->assertSame('claimed', $response->fresh()->verification_status);
    }

    public function test_only_an_accepted_donor_can_claim_and_pin_guessing_is_throttled(): void
    {
        $owner = $this->user();
        $donor = $this->user();
        $pending = $this->response($this->bloodRequest($owner), $donor, status: 'pending');

        $this->actingAs($donor)->post("/responses/{$pending->id}/claim", ['claim_method' => 'pin', 'pin' => $pending->verification_pin])
            ->assertForbidden();

        $accepted = $this->response($this->bloodRequest($owner), $donor);
        $wrong = $accepted->verification_pin === '0000' ? '1111' : '0000';
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->post("/responses/{$accepted->id}/claim", ['claim_method' => 'pin', 'pin' => $wrong])->status();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_a_bare_signup_with_a_referral_code_earns_nothing(): void
    {
        $referrer = $this->user(['referral_code' => 'FRIEND1']);

        $this->post('/register', [
            'name' => 'New', 'email' => 'new@example.test', 'password' => 'Password!234', 'password_confirmation' => 'Password!234',
            'registration_intent' => 'recipient', 'referred_by_code' => 'FRIEND1',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'referred_by' => $referrer->id]);
        $this->assertSame(0, (int) $referrer->fresh()->points);
    }

    public function test_onboarding_cannot_be_submitted_again_to_reset_the_last_donation(): void
    {
        $donor = $this->user(['is_onboarded' => true, 'last_donated_at' => now()->subDays(5)]);

        $this->actingAs($donor)->post('/onboarding', [
            'division_id' => $this->district->division_id, 'district_id' => $this->district->id, 'upazila_id' => $this->upazilaId,
            'gender' => 'male', 'weight' => 60,
        ]);

        $this->assertNotNull($donor->fresh()->last_donated_at);
    }

    private function offlineClaim(string $date)
    {
        return $this->post('/offline-claims', [
            'recipient_phone' => '01812345678', 'patient_name' => 'x', 'district_id' => $this->district->id, 'donation_date' => $date,
        ]);
    }

    public function test_offline_claims_cannot_be_backdated_or_stacked(): void
    {
        $this->actingAs($this->user());

        $this->offlineClaim(now()->subDays(400)->toDateString())->assertSessionHasErrors('donation_date');
        $this->offlineClaim(now()->subDays(3)->toDateString())->assertSessionHasNoErrors();
        $this->offlineClaim(now()->subDays(2)->toDateString())->assertSessionHasErrors();

        $this->assertSame(1, DB::table('offline_donation_claims')->count());
    }

    // ── SOS ─────────────────────────────────────────────────────────

    private function sos(User $user)
    {
        return $this->actingAs($user)->postJson('/sos/trigger', ['blood_group' => 'O+']);
    }

    public function test_sos_needs_a_phone_and_a_district(): void
    {
        Queue::fake();

        $this->sos($this->user(['phone' => null]))->assertStatus(422);
        $this->sos($this->user(['district_id' => null, 'upazila_id' => null]))->assertStatus(422);

        $this->assertSame(0, BloodRequest::count());
    }

    public function test_a_shadowbanned_account_cannot_send_sos(): void
    {
        Queue::fake();

        $this->sos($this->user(['is_shadowbanned' => true]))->assertForbidden();
    }

    public function test_sos_has_a_daily_limit(): void
    {
        Queue::fake();
        $user = $this->user();
        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->sos($user)->status();
            $this->travel(31)->minutes();
        }

        $this->assertSame([200, 200, 200, 429], $statuses);
    }

    // ── Script injection ────────────────────────────────────────────

    public function test_a_phone_number_cannot_contain_markup(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => '"><b onload=x()>'])
            ->assertSessionHasErrors('phone');
    }

    public function test_old_input_cannot_break_out_of_a_script_attribute(): void
    {
        $this->withSession(['_old_input' => ['hospital_display' => "');alert(1)//"]])
            ->get('/requests/create')
            ->assertOk()
            ->assertDontSee('&#039;);alert(1)', false);
    }
}
