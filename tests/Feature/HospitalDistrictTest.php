<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Hospital;
use Database\Seeders\HospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalDistrictTest extends TestCase
{
    use RefreshDatabase;

    private District $cumilla;
    private District $dhaka;
    private District $chattogram;

    protected function setUp(): void
    {
        parent::setUp();

        // Same order as public/data/bd_locations.json: কুমিল্লা gets id 1, which the old
        // seeder assumed was ঢাকা.
        $ctg = Division::create(['name' => 'চট্টগ্রাম']);
        $dhk = Division::create(['name' => 'ঢাকা']);
        $this->cumilla = District::create(['division_id' => $ctg->id, 'name' => 'কুমিল্লা']);
        $this->chattogram = District::create(['division_id' => $ctg->id, 'name' => 'চট্টগ্রাম']);
        $this->dhaka = District::create(['division_id' => $dhk->id, 'name' => 'ঢাকা']);
    }

    public function test_seeder_links_hospitals_by_district_name_not_by_position(): void
    {
        $this->assertSame(1, $this->cumilla->id, 'precondition: id 1 is not Dhaka');

        $this->seed(HospitalSeeder::class);

        $this->assertSame($this->dhaka->id, Hospital::where('name', 'Dhaka Medical College Hospital')->value('district_id'));
        $this->assertSame($this->chattogram->id, Hospital::where('name', 'Chittagong Medical College Hospital')->value('district_id'));
        $this->assertSame(0, Hospital::where('district_id', $this->cumilla->id)->count(), 'nothing lands in Cumilla');
    }

    public function test_migration_repairs_wrongly_seeded_rows_but_leaves_manual_fixes_alone(): void
    {
        $wrong = Hospital::create(['name' => 'Dhaka Medical College Hospital', 'district_id' => 1, 'is_verified' => true]);
        $fixedByHand = Hospital::create(['name' => 'Square Hospital', 'district_id' => $this->chattogram->id, 'is_verified' => true]);
        $userAdded = Hospital::create(['name' => 'Some Local Clinic', 'district_id' => 1, 'is_verified' => false]);

        $migration = require database_path('migrations/2026_11_24_000003_fix_seeded_hospital_districts.php');
        $migration->up();

        $this->assertSame($this->dhaka->id, $wrong->fresh()->district_id);
        $this->assertSame($this->chattogram->id, $fixedByHand->fresh()->district_id, 'a hand-corrected value is kept');
        $this->assertSame(1, $userAdded->fresh()->district_id, 'hospitals not from the seeder are untouched');
    }
}
