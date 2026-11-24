<?php

namespace Tests\Concerns;

use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use Database\Seeders\HospitalSeeder;

/**
 * Real divisions and districts from public/data/bd_locations.json, in the same order
 * as LocationSeeder (so ids match production: district 1 is কুমিল্লা, ঢাকা is 47).
 * LocationSeeder itself uses MySQL-only statements, so it cannot run on SQLite.
 */
trait SeedsBangladeshLocations
{
    protected function seedLocationsAndHospitals(bool $withUpazilas = true): void
    {
        $data = json_decode(file_get_contents(public_path('data/bd_locations.json')), true);

        foreach ($data['divisions'] as $divisionName => $districts) {
            $division = Division::create(['name' => $divisionName]);

            foreach ($districts as $districtName => $upazilas) {
                $district = District::create(['division_id' => $division->id, 'name' => $districtName]);

                if ($withUpazilas && $upazilas !== []) {
                    Upazila::create(['district_id' => $district->id, 'name' => $upazilas[0]]);
                }
            }
        }

        $this->seed(HospitalSeeder::class);
    }

    protected function districtId(string $name): int
    {
        return (int) District::where('name', $name)->value('id');
    }
}
