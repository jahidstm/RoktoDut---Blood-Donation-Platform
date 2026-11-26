<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastPanelTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('framework/testing/forecast_test.json');
        @mkdir(dirname($this->path), 0775, true);
        @unlink($this->path);
        config(['analytics.forecast_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function write(array $overrides = []): void
    {
        $week = now()->startOfWeek();
        $forecast = array_replace_recursive([
            'status' => 'ok',
            'data_source' => 'real',
            'generated_at' => now()->toIso8601String(),
            'horizon_weeks' => 4,
            'last_complete_week' => $week->copy()->subWeek()->toDateString(),
            'method_used' => 'ma13',
            'promoted' => false,
            'backtest' => ['folds' => 16, 'judging' => ['ma13' => ['wape' => 0.59, 'national_wape' => 0.3, 'mase' => 1.1]]],
            'intervals' => ['nominal' => 0.8,
                'national' => ['coverage_on_judging_folds' => 0.62, 'reliable' => false],
                'series' => ['coverage_on_judging_folds' => 0.76, 'reliable' => true]],
            'national' => [
                'history' => [['week' => $week->copy()->subWeek()->toDateString(), 'y' => 170]],
                'forecast' => [['week' => $week->toDateString(), 'mean' => 180.0, 'lo' => 150.0, 'hi' => 200.0]],
            ],
            'top_series' => [['division' => 'ঢাকা', 'blood_group' => 'O+',
                'next_week' => ['mean' => 18.4, 'lo' => 11.0, 'hi' => 26.0], 'four_week_total' => 73.0, 'last_four_weeks' => 70]],
        ], $overrides);

        file_put_contents($this->path, json_encode($forecast, JSON_UNESCAPED_UNICODE));
    }

    private function page()
    {
        return $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN->value]))->get('/admin/analytics?source=real');
    }

    public function test_no_file_shows_how_to_make_one(): void
    {
        $this->page()->assertOk()->assertSee('এখনো কোনো পূর্বাভাস তৈরি হয়নি', false);
    }

    public function test_baseline_forecast_names_its_method_and_hides_an_unreliable_interval(): void
    {
        $this->write();

        $this->page()->assertOk()
            ->assertSee('গত ১৩ সপ্তাহের গড়', false)
            ->assertSee('কোনো মডেল সহজ পদ্ধতির চেয়ে নিশ্চিতভাবে ভালো করেনি', false)
            ->assertSee('সম্ভাব্য সীমা দেখানো হচ্ছে না', false)
            ->assertSee('ঢাকা · O+', false)
            ->assertSee('(১১–২৬)', false)
            ->assertDontSee('সিমুলেটেড ইতিহাস', false);
    }

    public function test_simulated_and_stale_forecasts_are_labelled(): void
    {
        $old = now()->subWeeks(10)->startOfWeek()->toDateString();
        $this->write([
            'data_source' => 'simulated-seasonal',
            'method_used' => 'lightgbm',
            'promoted' => true,
            'backtest' => ['judging' => ['lightgbm' => ['wape' => 0.5, 'national_wape' => 0.09, 'mase' => 1.0]]],
            'national' => ['forecast' => [['week' => $old, 'mean' => 1.0, 'lo' => 0.0, 'hi' => 2.0]]],
        ]);

        $this->page()->assertOk()
            ->assertSee('সিমুলেটেড ইতিহাস', false)
            ->assertSee('এই পূর্বাভাস পুরোনো', false)
            ->assertSee('LightGBM মডেল', false);
    }

    public function test_insufficient_history_shows_nothing_made_up(): void
    {
        file_put_contents($this->path, json_encode(['status' => 'insufficient_history', 'data_source' => 'real', 'weeks' => 3, 'needed_weeks' => 40]));

        $this->page()->assertOk()->assertSee('যথেষ্ট ইতিহাস নেই', false)->assertDontSee('forecast-chart', false);
    }
}
