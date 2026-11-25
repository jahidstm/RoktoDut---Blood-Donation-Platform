<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class HeatmapTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function request(string $status, array $extra = []): void
    {
        $d = DB::table('districts')->where('name', 'ঢাকা')->first();
        DB::table('blood_requests')->insert(array_merge([
            'blood_group' => 'O+', 'component_type' => 'whole_blood', 'units_needed' => 1, 'bags_needed' => 1,
            'division_id' => $d->division_id, 'district_id' => $d->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $d->id)->value('id'),
            'contact_number' => '01700000000', 'urgency' => 'normal', 'status' => $status,
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN->value]);
    }

    public function test_open_demand_counts_approved_and_skips_hidden_closed_and_demo(): void
    {
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $this->request('pending');
        $this->request('approved');                       // was invisible before: only pending/in_progress counted
        $this->request('approved', ['is_hidden' => true]); // confirmed spam
        $this->request('fulfilled');                      // closed
        $this->request('pending', ['is_demo' => true]);
        $this->request('pending', ['is_demo' => true, 'blood_group' => 'A-']);

        $admin = $this->admin();

        $real = $this->actingAs($admin)->getJson('/admin/heatmap/data?source=real')->assertOk()->json();
        $this->assertSame(2, $real['Dhaka']['demand']);
        $this->assertSame(['O+' => 2], $real['Dhaka']['blood_groups']);
        $this->assertCount(64, $real);

        $demo = $this->actingAs($admin)->getJson('/admin/heatmap/data?source=demo')->assertOk()->json();
        $this->assertSame(2, $demo['Dhaka']['demand']);
        $this->assertArrayHasKey('A-', $demo['Dhaka']['blood_groups']);
    }

    public function test_public_api_never_returns_demo_rows(): void
    {
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $this->request('pending', ['is_demo' => true]);

        $this->getJson('/api/analytics/spatial-heatmap?source=demo')->assertOk()
            ->assertJsonPath('Dhaka.demand', 0);
    }

    public function test_page_renders_for_admins_only_and_labels_demo(): void
    {
        $this->actingAs($this->admin())->get('/admin/heatmap?source=demo')
            ->assertOk()
            ->assertSee('ডেমো ডেটা দেখানো হচ্ছে', false)
            ->assertSee('হিউরিস্টিক সূচক', false);

        $this->actingAs(User::factory()->create(['role' => UserRole::DONOR->value]))
            ->get('/admin/heatmap/data')->assertStatus(403);
    }
}
