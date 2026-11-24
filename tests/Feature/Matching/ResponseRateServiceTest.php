<?php

namespace Tests\Feature\Matching;

use App\Models\DonorResponseLog;
use App\Services\Matching\ResponseRateService;

class ResponseRateServiceTest extends MatchingTestCase
{
    private function log(int $donorId, string $status): void
    {
        DonorResponseLog::create([
            'request_id' => $this->request('A+')->id,
            'donor_id' => $donorId,
            'notified_at' => now()->subDay(),
            'status' => $status,
            'distance_km' => 2,
            'days_since_last_donation' => 200,
            'temporal_hour' => 10,
            'is_weekend' => false,
            'historical_response_rate' => 0,
        ]);
    }

    public function test_new_donor_gets_the_prior_not_zero(): void
    {
        $newcomer = $this->donor('A+');

        $this->assertSame([$newcomer->id => 0.3], app(ResponseRateService::class)->forDonors([$newcomer->id]));
    }

    public function test_pending_rows_do_not_count_and_answers_are_smoothed(): void
    {
        $donor = $this->donor('A+');
        foreach (['accepted', 'accepted', 'accepted', 'declined', 'pending', 'pending'] as $status) {
            $this->log($donor->id, $status);
        }

        // (3 + 5 * 0.3) / (4 + 5) = 0.5; the two pending rows are "not known yet"
        $this->assertSame(0.5, app(ResponseRateService::class)->forDonors([$donor->id])[$donor->id]);
    }

    public function test_ignored_counts_as_an_answer(): void
    {
        $donor = $this->donor('A+');
        foreach (range(1, 5) as $_) {
            $this->log($donor->id, 'ignored');
        }

        // (0 + 1.5) / (5 + 5) = 0.15: a donor who never answers drifts below the prior
        $this->assertSame(0.15, app(ResponseRateService::class)->forDonors([$donor->id])[$donor->id]);
    }
}
