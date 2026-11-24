<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HospitalSeeder used fixed district ids (1 = "Dhaka", 20 = "Chittagong", ...), but ids follow
 * the order of public/data/bd_locations.json, where 1 is কুমিল্লা and ঢাকা is 47. Every seeded
 * hospital therefore pointed at the wrong district, and district-filtered hospital search
 * could not find them (e.g. Dhaka Medical College Hospital sat in Cumilla).
 *
 * Only rows that still carry the old wrong id are changed, so a hospital an admin has
 * already corrected by hand is left alone. Hospitals added by users are not touched.
 */
return new class extends Migration
{
    /** [hospital name, wrong id the seeder used, correct district name] */
    private const SEEDED = [
        ['Dhaka Medical College Hospital', 1, 'ঢাকা'],
        ['Bangabandhu Sheikh Mujib Medical University', 1, 'ঢাকা'],
        ['Sir Salimullah Medical College & Mitford Hospital', 1, 'ঢাকা'],
        ['National Institute of Cardiovascular Diseases', 1, 'ঢাকা'],
        ['National Institute of Neurosciences', 1, 'ঢাকা'],
        ['Shohrawardi Medical College Hospital', 1, 'ঢাকা'],
        ['Square Hospital', 1, 'ঢাকা'],
        ['Evercare Hospital Dhaka', 1, 'ঢাকা'],
        ['United Hospital', 1, 'ঢাকা'],
        ['Ibn Sina Hospital', 1, 'ঢাকা'],
        ['Labaid Specialized Hospital', 1, 'ঢাকা'],
        ['Popular Diagnostic Centre', 1, 'ঢাকা'],
        ['Chittagong Medical College Hospital', 20, 'চট্টগ্রাম'],
        ['Chevron Clinical Laboratory', 20, 'চট্টগ্রাম'],
        ['Rajshahi Medical College Hospital', 41, 'রাজশাহী'],
        ['Khulna Medical College Hospital', 47, 'খুলনা'],
        ['Sylhet MAG Osmani Medical College Hospital', 57, 'সিলেট'],
        ['Sher-E-Bangla Medical College Hospital', 10, 'বরিশাল'],
        ['Rangpur Medical College Hospital', 55, 'রংপুর'],
        ['Mymensingh Medical College Hospital', 34, 'ময়মনসিংহ'],
        ['National Orthopaedic Hospital and Rehabilitation Institute', 1, 'ঢাকা'],
        ['National Cancer Institute and Hospital', 1, 'ঢাকা'],
        ['National Institute of Kidney Diseases and Urology', 1, 'ঢাকা'],
        ['National Eye Care', 1, 'ঢাকা'],
        ['Kurmitola General Hospital', 1, 'ঢাকা'],
        ['Mugda Medical College and Hospital', 1, 'ঢাকা'],
        ['Anwer Khan Modern Medical College Hospital', 1, 'ঢাকা'],
    ];

    public function up(): void
    {
        $districtIds = DB::table('districts')->pluck('id', 'name');

        foreach (self::SEEDED as [$hospital, $wrongId, $districtName]) {
            $correctId = $districtIds[$districtName] ?? null;

            if ($correctId === null || (int) $correctId === $wrongId) {
                continue;
            }

            DB::table('hospitals')
                ->where('name', $hospital)
                ->where('district_id', $wrongId)
                ->update(['district_id' => $correctId]);
        }
    }

    public function down(): void
    {
        // Not reversible on purpose: restoring the wrong districts has no use.
    }
};
