<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class ExportResponseLogsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function requestId(bool $demo): int
    {
        $d = DB::table('districts')->where('name', 'ঢাকা')->first();

        return DB::table('blood_requests')->insertGetId([
            'blood_group' => 'O+', 'component_type' => 'whole_blood', 'units_needed' => 1, 'bags_needed' => 1,
            'division_id' => $d->division_id, 'district_id' => $d->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $d->id)->value('id'),
            'contact_number' => '01700000000', 'urgency' => 'urgent', 'status' => 'approved',
            'is_demo' => $demo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_export_is_anonymised_point_in_time_ready_and_skips_demo_by_default(): void
    {
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $donor = User::factory()->create(['name' => 'Very Secret Donor', 'phone' => '01799999999']);

        foreach ([[false, 'accepted'], [true, 'ignored']] as [$demo, $status]) {
            DB::table('donor_response_logs')->insert([
                'request_id' => $this->requestId($demo), 'donor_id' => $donor->id,
                'notified_at' => '2026-03-01 10:00:00', 'responded_at' => $status === 'accepted' ? '2026-03-01 10:20:00' : null,
                'status' => $status, 'distance_km' => 3.5, 'days_since_last_donation' => 200,
                'temporal_hour' => 10, 'is_weekend' => false, 'historical_response_rate' => 0.9,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('ml:export-response-logs', ['--path' => 'ml/test_logs.csv'])->assertSuccessful();
        $file = storage_path('app/ml/test_logs.csv');
        $lines = array_map('str_getcsv', array_filter(explode("\n", file_get_contents($file))));
        @unlink($file);

        $header = $lines[0];
        $this->assertCount(2, $lines, 'header + the one real row; the demo row is skipped');
        $this->assertNotContains('historical_response_rate', $header, 'the trainer recomputes it point in time');
        $this->assertNotContains('donor_id', $header);

        $row = array_combine($header, $lines[1]);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('urgent', $row['urgency']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['donor_key']);
        $this->assertNotSame((string) $donor->id, $row['donor_key']);
        $this->assertStringNotContainsString('Secret', implode(',', $lines[1]));
        $this->assertStringNotContainsString('01799999999', implode(',', $lines[1]));

        $this->artisan('ml:export-response-logs', ['--path' => 'ml/test_logs.csv', '--include-demo' => true])->assertSuccessful();
        $this->assertCount(3, array_filter(explode("\n", file_get_contents($file))));
        @unlink($file);
    }
}
