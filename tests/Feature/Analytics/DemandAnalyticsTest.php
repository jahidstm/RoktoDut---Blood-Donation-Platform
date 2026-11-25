<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Analytics\DemandAnalytics;
use App\Services\Analytics\DemoDemandGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class DemandAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private CarbonImmutable $now;
    private int $districtId;
    private int $divisionId;
    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals(withUpazilas: true);

        $this->now = CarbonImmutable::parse('2026-06-17 12:00:00'); // a Wednesday
        $district = DB::table('districts')->where('name', 'ঢাকা')->first();
        $this->districtId = $district->id;
        $this->divisionId = $district->division_id;
        $this->upazilaId = DB::table('upazilas')->where('district_id', $district->id)->value('id');
    }

    /** @return int request id */
    private function request(string $group, string $status, CarbonImmutable $createdAt, array $extra = []): int
    {
        return DB::table('blood_requests')->insertGetId(array_merge([
            'blood_group' => $group,
            'component_type' => 'whole_blood',
            'units_needed' => 1,
            'bags_needed' => 1,
            'division_id' => $this->divisionId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'contact_number' => '01700000000',
            'urgency' => 'normal',
            'status' => $status,
            'is_demo' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $extra));
    }

    private function fulfil(int $requestId, CarbonImmutable $at): void
    {
        $donor = User::factory()->create();
        DB::table('blood_request_responses')->insert([
            'blood_request_id' => $requestId, 'user_id' => $donor->id, 'status' => 'accepted',
            'fulfilled_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function compute(string $source = DemandAnalytics::SOURCE_REAL): array
    {
        return app(DemandAnalytics::class)->compute($source, $this->now);
    }

    public function test_fulfilment_rate_ignores_requests_too_young_to_have_been_fulfilled(): void
    {
        $old = $this->now->subDays(20);
        $a = $this->request('O+', 'fulfilled', $old);
        $this->fulfil($a, $old->addHours(6));
        $this->request('O+', 'approved', $old);       // old and still unfulfilled: counts against the rate
        $this->request('O+', 'pending', $this->now->subDays(1)); // yesterday: too young, left out of the rate

        $kpi = $this->compute()['kpi'];

        $this->assertSame(3, $kpi['requests']);
        $this->assertSame(2, $kpi['mature_requests']);
        $this->assertSame(0.5, $kpi['fulfilment_rate']);
    }

    public function test_time_to_fulfil_uses_the_first_fulfilment_and_reports_median_and_p90(): void
    {
        $base = $this->now->subDays(30);
        foreach ([2, 4, 6, 8, 50] as $hours) {
            $id = $this->request('B+', 'fulfilled', $base);
            $this->fulfil($id, $base->addHours($hours));
        }
        // A second, later fulfilment on the first request must not change its time.
        $first = DB::table('blood_requests')->orderBy('id')->value('id');
        $this->fulfil($first, $base->addHours(90));

        $kpi = $this->compute()['kpi'];

        $this->assertSame(6.0, $kpi['median_hours_to_fulfil']);
        $this->assertSame(50.0, $kpi['p90_hours_to_fulfil']);
        $this->assertSame(5, $kpi['timed_requests']);
    }

    public function test_no_data_gives_nulls_not_zeros_or_errors(): void
    {
        $data = $this->compute();

        $this->assertSame(0, $data['kpi']['requests']);
        $this->assertNull($data['kpi']['fulfilment_rate']);
        $this->assertNull($data['kpi']['median_hours_to_fulfil']);
        $this->assertNull($data['donor_response']['accept_rate']);
        $this->assertCount(12, $data['weekly']);
    }

    public function test_demo_real_and_all_are_kept_apart(): void
    {
        $when = $this->now->subDays(10);
        $this->request('A+', 'approved', $when);
        $this->request('A+', 'approved', $when, ['is_demo' => true]);
        $this->request('A+', 'approved', $when, ['is_demo' => true]);

        $this->assertSame(1, $this->compute('real')['kpi']['requests']);
        $this->assertSame(2, $this->compute('demo')['kpi']['requests']);
        $this->assertSame(3, $this->compute('all')['kpi']['requests']);
    }

    public function test_drafts_and_hidden_requests_are_not_demand(): void
    {
        $when = $this->now->subDays(10);
        $this->request('A+', 'nlp_pending', $when);
        $this->request('A+', 'approved', $when, ['is_hidden' => true]);
        $this->request('A+', 'approved', $when);

        $this->assertSame(1, $this->compute()['kpi']['requests']);
    }

    public function test_weekly_buckets_include_empty_weeks_and_count_by_creation_week(): void
    {
        $this->request('O+', 'approved', $this->now->subDays(1));   // this week (Mon 15 Jun)
        $this->request('O+', 'approved', $this->now->subDays(15));  // two weeks earlier

        $weekly = $this->compute()['weekly'];

        $this->assertSame('2026-06-15', $weekly[11]['week_start']);
        $this->assertSame(1, $weekly[11]['created']);
        $this->assertSame(1, $weekly[9]['created']);
        $this->assertSame(0, $weekly[10]['created']);
    }

    public function test_group_demand_share_sits_next_to_donor_share(): void
    {
        $when = $this->now->subDays(10);
        $this->request('O-', 'approved', $when, ['units_needed' => 3]);
        $this->request('A+', 'approved', $when, ['units_needed' => 1]);
        User::factory()->count(3)->create(['is_donor' => true, 'blood_group' => 'A+']);
        User::factory()->create(['is_donor' => true, 'blood_group' => 'O-']);

        $rows = collect($this->compute()['by_blood_group'])->keyBy('group');

        $this->assertSame(0.75, $rows['O-']['demand_share']);
        $this->assertSame(0.25, $rows['O-']['donor_share']);
        $this->assertSame(0.25, $rows['A+']['demand_share']);
        $this->assertSame(0.75, $rows['A+']['donor_share']);
    }

    public function test_donor_response_rate_excludes_pending_and_moot(): void
    {
        $id = $this->request('O+', 'approved', $this->now->subDays(10));
        $donors = User::factory()->count(5)->create();
        foreach (['accepted', 'accepted', 'declined', 'pending', 'moot'] as $i => $status) {
            DB::table('donor_response_logs')->insert([
                'request_id' => $id, 'donor_id' => $donors[$i]->id, 'notified_at' => $this->now,
                'status' => $status, 'days_since_last_donation' => 100, 'temporal_hour' => 10,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        $resp = $this->compute()['donor_response'];

        $this->assertSame(3, $resp['answered']);
        $this->assertEqualsWithDelta(0.6667, $resp['accept_rate'], 0.0001);
    }

    public function test_generator_is_deterministic_flagged_and_purges_only_demo(): void
    {
        User::factory()->count(40)->create(['is_donor' => true]);
        $real = $this->request('O+', 'approved', $this->now->subDays(5));

        $gen = app(DemoDemandGenerator::class);
        $made = $gen->generate(120, 4, 7, $this->now);
        $firstRun = DB::table('blood_requests')->where('is_demo', true)->orderBy('id')
            ->get(['blood_group', 'district_id', 'status', 'created_at'])->toJson();

        $this->assertSame(120, $made['requests']);
        $this->assertSame(120, DB::table('blood_requests')->where('is_demo', true)->count());
        $this->assertGreaterThan(0, $made['responses']);

        $gen->purge();
        $this->assertSame(0, DB::table('blood_requests')->where('is_demo', true)->count());
        $this->assertSame(0, DB::table('blood_request_responses')->whereIn('blood_request_id', function ($q) use ($real) {
            $q->select('id')->from('blood_requests')->where('id', '!=', $real);
        })->count(), 'responses go with their request');
        $this->assertTrue(DB::table('blood_requests')->where('id', $real)->exists(), 'real request must survive a purge');

        $gen->generate(120, 4, 7, $this->now);
        $secondRun = DB::table('blood_requests')->where('is_demo', true)->orderBy('id')
            ->get(['blood_group', 'district_id', 'status', 'created_at'])->toJson();
        $this->assertSame($firstRun, $secondRun, 'same seed must give the same data');
    }

    public function test_generated_demo_data_flows_through_the_dashboard_numbers(): void
    {
        User::factory()->count(40)->create(['is_donor' => true]);
        app(DemoDemandGenerator::class)->generate(300, 6, 42, $this->now);

        $data = $this->compute('demo');

        $this->assertGreaterThan(250, $data['kpi']['requests']);
        $this->assertGreaterThan(0.4, $data['kpi']['fulfilment_rate']);
        $this->assertLessThan(0.95, $data['kpi']['fulfilment_rate']);
        $this->assertNotNull($data['kpi']['median_hours_to_fulfil']);
        $this->assertGreaterThan(0, $data['donor_response']['answered']);
        $this->assertSame(0, $this->compute('real')['kpi']['requests'], 'demo rows never leak into real');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN->value]);
    }

    public function test_dashboard_opens_on_demo_with_a_banner_when_real_data_is_too_thin(): void
    {
        User::factory()->count(10)->create(['is_donor' => true]);
        app(DemoDemandGenerator::class)->generate(60, 3, 1);

        $this->actingAs($this->admin())->get('/admin/analytics')
            ->assertOk()
            ->assertSee('ডেমো ডেটা', false)
            ->assertSee('আসল রিকোয়েস্ট এখন মাত্র', false);
    }

    public function test_dashboard_can_be_forced_to_real_data_and_non_admins_are_blocked(): void
    {
        app(DemoDemandGenerator::class); // class resolves
        $this->actingAs($this->admin())->get('/admin/analytics?source=real')
            ->assertOk()
            ->assertDontSee('এই পাতার রিকোয়েস্টগুলো কম্পিউটারে বানানো', false);

        $donor = User::factory()->create(['role' => UserRole::DONOR->value]);
        $this->actingAs($donor)->get('/admin/analytics')->assertStatus(403);
    }

    public function test_flat_export_has_no_personal_columns_and_respects_source(): void
    {
        $when = $this->now->subDays(10);
        $real = $this->request('O+', 'fulfilled', $when, ['patient_name' => 'Secret Name', 'contact_name' => 'Hidden', 'notes' => 'private']);
        $this->fulfil($real, $when->addHours(5));
        $this->request('A+', 'approved', $when, ['is_demo' => true]);

        $this->artisan('analytics:export', ['--source' => 'real', '--path' => 'analytics/test_flat.csv'])->assertSuccessful();

        $file = storage_path('app/analytics/test_flat.csv');
        $csv = file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('hours_to_fulfil', $csv);
        $this->assertStringContainsString(',5', $csv);
        $this->assertStringNotContainsString('Secret Name', $csv);
        $this->assertStringNotContainsString('01700000000', $csv);
        $this->assertStringNotContainsString('A+', $csv, 'demo row must not appear in the real export');
    }
}
