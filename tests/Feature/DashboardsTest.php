<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\BloodRequestMatchedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class DashboardsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function orgAdmin(object $district): User
    {
        $admin = User::factory()->create(['role' => 'org_admin', 'is_onboarded' => true]);
        $org = Organization::create([
            'name' => 'Test Club', 'type' => 'club', 'district' => $district->id, 'phone' => '01700000000',
            'admin_id' => $admin->id, 'status' => 'approved', 'is_verified' => true,
        ]);
        $admin->forceFill(['organization_id' => $org->id])->save();

        return $admin->fresh();
    }

    private function member(User $admin, string $group): User
    {
        return User::factory()->create([
            'role' => 'donor', 'organization_id' => $admin->organization_id, 'blood_group' => $group,
            'nid_status' => 'verified', 'is_available' => true, 'is_onboarded' => true,
        ]);
    }

    private function bloodRequest(object $district): BloodRequest
    {
        return BloodRequest::create([
            'requested_by' => User::factory()->create(['role' => 'recipient'])->id,
            'patient_name' => 'Patient', 'blood_group' => 'A+', 'component_type' => 'whole_blood', 'bags_needed' => 1,
            'division_id' => $district->division_id, 'district_id' => $district->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $district->id)->value('id'),
            'contact_number' => '01700000001', 'urgency' => 'emergency', 'status' => 'pending',
            'needed_at' => now()->addDay(),
        ]);
    }

    public function test_a_broadcast_notifies_only_members_who_can_help(): void
    {
        $this->seedLocationsAndHospitals();
        $district = DB::table('districts')->first();
        $admin = $this->orgAdmin($district);
        $match = $this->member($admin, 'A+');
        $wrongGroup = $this->member($admin, 'B+');
        $request = $this->bloodRequest($district);
        Notification::fake();

        $this->actingAs($admin)->post(route('org.requests.broadcast', $request))->assertSessionHas('success');

        Notification::assertSentTo($match, BloodRequestMatchedNotification::class);
        Notification::assertNotSentTo($wrongGroup, BloodRequestMatchedNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.request.broadcast']);
    }

    public function test_requests_outside_the_area_or_hidden_cannot_be_broadcast(): void
    {
        $this->seedLocationsAndHospitals();
        $district = DB::table('districts')->first();
        $admin = $this->orgAdmin($district);
        $hidden = $this->bloodRequest($district);
        $hidden->update(['is_hidden' => true]);

        $this->actingAs($admin)->post(route('org.requests.broadcast', $hidden))->assertNotFound();
    }

    public function test_the_org_overview_links_to_what_needs_doing(): void
    {
        $this->seedLocationsAndHospitals();
        $admin = $this->orgAdmin(DB::table('districts')->first());

        $this->actingAs($admin)->get(route('org.dashboard'))
            ->assertOk()
            ->assertSee('যাচাই বাকি মেম্বার')
            ->assertSee('speculationrules', false);
    }

    public function test_long_term_patients_is_reachable_from_donor_and_recipient_dashboards(): void
    {
        foreach (['donor', 'recipient'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_onboarded' => true]);

            $this->actingAs($user)->get(route('chronic.index'))
                ->assertOk()
                ->assertSee('data-tab="chronic"', false)
                ->assertSee('speculationrules', false);
        }
    }
}
