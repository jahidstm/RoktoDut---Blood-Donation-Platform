<?php

namespace Tests\Feature\Matching;

use App\Models\DonorResponseLog;
use App\Services\DfiCalculationService;
use App\Services\DonorResponseLogger;
use App\Services\Matching\DonorReliabilityService;
use App\Services\Matching\ResponseRateService;

class DonorReliabilityTest extends MatchingTestCase
{
    private function log(int $donorId, string $status, ?int $minutes = null, $notifiedAt = null, ?int $requestId = null): DonorResponseLog
    {
        return DonorResponseLog::create([
            'request_id' => $requestId ?? $this->request('A+')->id,
            'donor_id' => $donorId,
            'notified_at' => $notifiedAt ?? now()->subDays(3),
            'responded_at' => $minutes === null ? null : now()->subDays(3)->addMinutes($minutes),
            'response_time_minutes' => $minutes,
            'status' => $status,
            'distance_km' => 2,
            'days_since_last_donation' => 200,
            'temporal_hour' => 10,
            'is_weekend' => false,
            'historical_response_rate' => 0,
        ]);
    }

    private function many(int $donorId, string $status, int $times): void
    {
        foreach (range(1, $times) as $_) {
            $this->log($donorId, $status);
        }
    }

    public function test_donor_with_no_history_is_new_and_sits_at_the_prior(): void
    {
        $r = app(DonorReliabilityService::class)->forDonor($this->donor('A+'));

        $this->assertSame('new', $r['level']);
        $this->assertSame('low', $r['confidence']);
        $this->assertSame(0, $r['notified']);
        $this->assertSame(30, $r['score'], 'prior mean 0.3, not 0');
        $this->assertNull($r['median_response_minutes']);
        $this->assertNull($r['answered_by_choice_rate']);
    }

    public function test_counts_and_median_response_time(): void
    {
        $d = $this->donor('A+');
        $this->log($d->id, 'accepted', 10);
        $this->log($d->id, 'accepted', 40);
        $this->log($d->id, 'declined', 20);
        $this->log($d->id, 'ignored');
        $this->log($d->id, 'pending', null, now()->subHour());

        $r = app(DonorReliabilityService::class)->forDonor($d);

        $this->assertSame(5, $r['notified']);
        $this->assertSame(['accepted' => 2, 'declined' => 1, 'ignored' => 1, 'pending' => 1, 'moot' => 0],
            array_intersect_key($r, array_flip(['accepted', 'declined', 'ignored', 'pending', 'moot'])));
        $this->assertSame(4, $r['answered'], 'pending is not an answer');
        $this->assertSame(20, $r['median_response_minutes'], 'median of replies 10, 20, 40');
        $this->assertSame(0.75, $r['answered_by_choice_rate'], '3 of 4 answers were a real reply');
    }

    public function test_even_number_of_replies_averages_the_middle_two(): void
    {
        $d = $this->donor('A+');
        foreach ([10, 20, 30, 50] as $m) {
            $this->log($d->id, 'accepted', $m);
        }

        $this->assertSame(25, app(DonorReliabilityService::class)->forDonor($d)['median_response_minutes']);
    }

    public function test_score_equals_the_rate_the_ranking_uses(): void
    {
        $d = $this->donor('A+');
        $this->many($d->id, 'accepted', 6);
        $this->many($d->id, 'declined', 3);
        $this->many($d->id, 'ignored', 1);

        $score = app(DonorReliabilityService::class)->forDonor($d)['score'];
        $ranking = app(ResponseRateService::class)->forDonors([$d->id])[$d->id];

        $this->assertSame((int) round($ranking * 100), $score);
    }

    public function test_levels_and_confidence_follow_the_configured_bands(): void
    {
        $service = app(DonorReliabilityService::class);

        $few = $this->donor('A+');
        $this->many($few->id, 'accepted', 4);          // 4 answers < 5: not enough history
        $this->assertSame('new', $service->forDonor($few)['level']);

        $high = $this->donor('A+');
        $this->many($high->id, 'accepted', 9);
        $this->many($high->id, 'declined', 1);         // (9+1.5)/(10+5) = 0.70
        $this->assertSame('high', $service->forDonor($high)['level']);
        $this->assertSame('medium', $service->forDonor($high)['confidence']);

        $medium = $this->donor('A+');
        $this->many($medium->id, 'accepted', 4);
        $this->many($medium->id, 'ignored', 6);        // (4+1.5)/(10+5) = 0.37 -> below medium 0.4
        $this->assertSame('low', $service->forDonor($medium)['level']);

        $mid = $this->donor('A+');
        $this->many($mid->id, 'accepted', 5);
        $this->many($mid->id, 'ignored', 5);           // (5+1.5)/(10+5) = 0.43
        $this->assertSame('medium', $service->forDonor($mid)['level']);

        $veteran = $this->donor('A+');
        $this->many($veteran->id, 'accepted', 15);
        $this->assertSame('high', $service->forDonor($veteran)['confidence']);
    }

    // ── "moot": settled by someone else, must never count against the donor ──

    public function test_stale_pending_becomes_moot_when_the_request_was_settled_else_ignored(): void
    {
        $donor = $this->donor('A+');
        $old = now()->subHours(30);

        $fulfilled = $this->request('A+', ['status' => 'fulfilled']);
        $rejected = $this->request('A+', ['status' => 'rejected']);
        $open = $this->request('A+', ['status' => 'pending']);
        $recentRequest = $this->request('A+', ['status' => 'fulfilled']);

        $a = $this->log($donor->id, 'pending', null, $old, $fulfilled->id);
        $b = $this->log($donor->id, 'pending', null, $old, $rejected->id);
        $c = $this->log($donor->id, 'pending', null, $old, $open->id);
        $d = $this->log($donor->id, 'pending', null, now()->subHours(2), $recentRequest->id);
        $e = $this->log($donor->id, 'accepted', 15, $old, $fulfilled->id);   // answered rows are never touched

        app(DonorResponseLogger::class)->markStaleAsIgnored();

        $this->assertSame('moot', $a->fresh()->status);
        $this->assertSame('moot', $b->fresh()->status);
        $this->assertSame('ignored', $c->fresh()->status);
        $this->assertSame('pending', $d->fresh()->status, 'not stale yet');
        $this->assertSame('accepted', $e->fresh()->status);
    }

    public function test_moot_counts_neither_for_the_rate_nor_for_fatigue(): void
    {
        $donor = $this->donor('A+');
        $this->many($donor->id, 'moot', 8);

        $r = app(DonorReliabilityService::class)->forDonor($donor);

        $this->assertSame(8, $r['moot']);
        $this->assertSame(0, $r['answered']);
        $this->assertSame(30, $r['score'], 'eight moot rows leave the score at the prior');
        $this->assertSame('new', $r['level']);
        $this->assertSame(0, app(DfiCalculationService::class)->countIgnoredRequests($donor->id), 'no fatigue from moot');
    }

    public function test_moot_cannot_be_overwritten_by_a_late_response(): void
    {
        // A donor who answers after the request settled still produces a clean record of their answer.
        $donor = $this->donor('A+');
        $log = $this->log($donor->id, 'moot', null, now()->subHours(30), $this->request('A+', ['status' => 'fulfilled'])->id);

        $this->assertTrue(app(DonorResponseLogger::class)->recordResponse($log->request_id, $donor->id, 'pending'));
        $this->assertSame('accepted', $log->fresh()->status);
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_shows_the_donor_their_own_history_only(): void
    {
        $me = $this->donor('A+', ['is_onboarded' => true]);
        $other = $this->donor('B+', ['is_onboarded' => true]);

        $this->many($me->id, 'accepted', 3);
        $this->many($me->id, 'declined', 2);
        $this->many($other->id, 'ignored', 7);

        $response = $this->actingAs($me)->get(route('donor.dashboard'));

        $response->assertOk()->assertSee('data-testid="reliability-card"', false);

        $card = $this->cardHtml($response->getContent());
        $this->assertStringContainsString('>3<', $card, 'my 3 accepted');
        $this->assertStringContainsString('>2<', $card, 'my 2 declined');
        $this->assertStringNotContainsString('>7<', $card, "another donor's 7 ignored must not leak");
    }

    public function test_dashboard_says_so_when_the_donor_was_never_notified(): void
    {
        $me = $this->donor('A+', ['is_onboarded' => true]);

        $this->actingAs($me)->get(route('donor.dashboard'))
            ->assertOk()
            ->assertSee('এখনো কোনো অনুরোধে আপনাকে জানানো হয়নি');
    }

    private function cardHtml(string $html): string
    {
        $start = strpos($html, 'data-testid="reliability-card"');
        $this->assertNotFalse($start);

        return substr($html, $start, 4000);
    }
}
