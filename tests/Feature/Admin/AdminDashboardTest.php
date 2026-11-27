<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Ambulance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN->value]);
    }

    public function test_the_database_accepts_a_rejected_donation_claim(): void
    {
        $column = collect(DB::select("select sql from sqlite_master where name = 'blood_request_responses'"))->first();

        $this->assertStringContainsString("'rejected'", $column->sql);
    }

    public function test_the_overview_renders_with_every_queue_card(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.risk.index'), false)
            ->assertSee(route('admin.spam-radar.index'), false)
            ->assertSee('speculationrules', false);
    }

    public function test_an_admin_decision_appears_in_the_audit_log_and_can_be_filtered(): void
    {
        $this->seedLocationsAndHospitals();
        $admin = $this->admin();
        $district = DB::table('districts')->first();
        $ambulance = Ambulance::create([
            'name' => 'Test Ambulance',
            'phone' => '01700000000',
            'type' => 'ac',
            'division_id' => $district->division_id,
            'district_id' => $district->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $district->id)->value('id'),
            'added_by' => $admin->id,
        ]);

        $this->actingAs($admin)->patch(route('admin.ambulances.verify', $ambulance))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.ambulance.verify', 'actor_user_id' => $admin->id]);
        $this->get(route('admin.audit-logs.index', ['area' => 'admin']))->assertSee('admin.ambulance.verify');
        $this->get(route('admin.audit-logs.index', ['area' => 'privacy']))->assertDontSee('admin.ambulance.verify');
    }
}
