<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

/** The public map shares the admin map's view, so it must not leak the admin-only parts. */
class LiveDemandPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    public function test_the_public_map_has_no_admin_controls(): void
    {
        $this->seedLocationsAndHospitals();

        $this->get('/live-demand')
            ->assertOk()
            ->assertSee('রক্তের অনুরোধ করুন')
            ->assertSee('"district_id"', false)
            ->assertDontSee('id="f-source"', false)
            ->assertDontSee('id="export"', false)
            ->assertDontSee('ডেমো ডেটা দেখানো হচ্ছে')
            ->assertDontSee('/admin/', false);
    }

    public function test_the_admin_map_keeps_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN->value]))
            ->get('/admin/heatmap')
            ->assertOk()
            ->assertSee('id="f-source"', false)
            ->assertSee('id="export"', false);
    }
}
