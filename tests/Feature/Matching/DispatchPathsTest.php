<?php

namespace Tests\Feature\Matching;

use App\Jobs\DispatchEmergencyAlertsJob;
use App\Jobs\SmartBloodRequestBroadcastJob;
use App\Jobs\SosBloodRequestBroadcastJob;
use App\Services\DfiCalculationService;
use App\Services\DonorMatchingService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * The three ways donors get ranked all go through the same hard rules now.
 */
class DispatchPathsTest extends MatchingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.roktodut_ml.base_url' => 'http://ml.test', 'services.roktodut_ml.api_key' => 'k']);
        Redis::shouldReceive('zrangebyscore')->andReturn([])->byDefault();
    }

    private function dispatchedRanking(): array
    {
        $ranking = null;
        Queue::assertPushed(DispatchEmergencyAlertsJob::class, function ($job) use (&$ranking) {
            $ranking = $job->rankedDonors;

            return true;
        });

        return $ranking;
    }

    // ── SmartBloodRequestBroadcastJob ──────────────────────────────────────

    public function test_smart_job_ranks_identical_group_above_closer_compatible_donor(): void
    {
        Queue::fake([DispatchEmergencyAlertsJob::class]);
        $universal = $this->donor('O-', $this->north(1));
        $identical = $this->donor('A+', $this->north(8));

        $request = $this->request('A+', ['component_type' => 'packed_rbc']);
        (new SmartBloodRequestBroadcastJob($request->id))->handle(app(DfiCalculationService::class));

        $this->assertSame([$identical->id, $universal->id], array_column($this->dispatchedRanking(), 'donor_id'));
    }

    public function test_smart_job_no_longer_drops_new_donors_in_quiet_hours(): void
    {
        // 02:00 Dhaka. A new donor used to have rate 0.0 < 0.2 and was excluded at night.
        $this->travelTo(now('Asia/Dhaka')->setTime(2, 0)->utc());
        Queue::fake([DispatchEmergencyAlertsJob::class]);
        $newcomer = $this->donor('A+');

        $request = $this->request('A+');
        (new SmartBloodRequestBroadcastJob($request->id))->handle(app(DfiCalculationService::class));

        $row = $this->dispatchedRanking()[0];
        $this->assertSame($newcomer->id, $row['donor_id']);
        $this->assertSame(0.3, $row['historical_response_rate']);
        $this->assertSame(365, $row['days_since_last_donation'], 'never donated = 365, same as the ML path');
    }

    public function test_smart_job_without_coordinates_sends_null_distance(): void
    {
        Queue::fake([DispatchEmergencyAlertsJob::class]);
        $this->donor('A+', ['latitude' => null, 'longitude' => null]);

        $request = $this->request('A+', ['latitude' => null, 'longitude' => null]);
        (new SmartBloodRequestBroadcastJob($request->id))->handle(app(DfiCalculationService::class));

        $this->assertNull($this->dispatchedRanking()[0]['distance_km']);
    }

    // ── SosBloodRequestBroadcastJob ────────────────────────────────────────

    public function test_sos_reaches_unavailable_donors_but_never_ones_in_cooldown(): void
    {
        Queue::fake([DispatchEmergencyAlertsJob::class]);
        $off = $this->donor('A+', ['is_available' => false]);
        $this->donor('A+', ['last_whole_blood_donated_at' => now()->subDays(20)->toDateString()]);

        $request = $this->request('A+', ['urgency' => 'emergency', 'is_super_critical' => true]);
        (new SosBloodRequestBroadcastJob($request->id))->handle(app(DfiCalculationService::class));

        $ranking = $this->dispatchedRanking();
        $this->assertSame([$off->id], array_column($ranking, 'donor_id'));
        $this->assertSame(365, $ranking[0]['days_since_last_donation'], 'was hardcoded to 0');
    }

    // ── DonorMatchingService (ML path) ─────────────────────────────────────

    public function test_ml_is_not_called_when_distance_is_unknown(): void
    {
        Http::fake();
        $this->donor('A+', ['latitude' => null, 'longitude' => null]);

        $ranked = app(DonorMatchingService::class)->rankDonors(
            $this->request('A+', ['latitude' => null, 'longitude' => null])
        );

        Http::assertNothingSent();
        $this->assertCount(1, $ranked);
        $this->assertNull($ranked[0]['distance_km']);
    }

    public function test_ml_orders_within_a_group_tier_but_cannot_put_compatible_above_identical(): void
    {
        $universal = $this->donor('O-', $this->north(1));
        $identicalA = $this->donor('A+', $this->north(5));
        $identicalB = $this->donor('A+', $this->north(9));

        // The model likes the O- donor best and prefers the farther A+ donor.
        Http::fake(['ml.test/*' => Http::response([
            ['donor_id' => $universal->id, 'probability_score' => 0.95, 'rank' => 1],
            ['donor_id' => $identicalB->id, 'probability_score' => 0.80, 'rank' => 2],
            ['donor_id' => $identicalA->id, 'probability_score' => 0.40, 'rank' => 3],
        ])]);

        $ranked = app(DonorMatchingService::class)->rankDonors(
            $this->request('A+', ['component_type' => 'packed_rbc']),
            limit: 3
        );

        $this->assertSame([$identicalB->id, $identicalA->id, $universal->id], $ranked->pluck('donor_id')->all());
        $this->assertSame([1, 2, 3], $ranked->pluck('rank')->all());

        Http::assertSent(function (HttpRequest $r) {
            $donor = $r['candidate_donors'][0];

            // only the six fields the model knows; nothing extra leaks into the payload
            return array_keys($donor) === ['donor_id', 'distance_km', 'days_since_last_donation', 'temporal_hour', 'is_weekend', 'historical_response_rate'];
        });
    }

    public function test_ml_failure_falls_back_to_identical_then_nearest(): void
    {
        Http::fake(['ml.test/*' => Http::response('boom', 500)]);
        $universal = $this->donor('O-', $this->north(1));
        $far = $this->donor('A+', $this->north(12));
        $near = $this->donor('A+', $this->north(4));

        $ranked = app(DonorMatchingService::class)->rankDonors(
            $this->request('A+', ['component_type' => 'packed_rbc']),
            limit: 3
        );

        $this->assertSame([$near->id, $far->id, $universal->id], $ranked->pluck('donor_id')->all());
    }

    public function test_emergency_requests_do_not_exclude_fatigued_donors(): void
    {
        Http::fake(['ml.test/*' => Http::response('down', 500)]);
        $tired = $this->donor('A+');

        Redis::shouldReceive('zrangebyscore')->andReturn([(string) $tired->id]);

        $normal = app(DonorMatchingService::class)->rankDonors($this->request('A+', ['urgency' => 'urgent']));
        $emergency = app(DonorMatchingService::class)->rankDonors($this->request('A+', ['urgency' => 'emergency']));

        $this->assertCount(0, $normal, 'fatigued donor skipped for a normal request');
        $this->assertSame([$tired->id], $emergency->pluck('donor_id')->all(), 'was always skipped: "EMERGENCY" vs "emergency"');
    }
}
