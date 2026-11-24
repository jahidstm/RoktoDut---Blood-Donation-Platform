<?php

namespace Tests\Feature;

use App\Enums\BloodComponentType;
use App\Models\User;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private EligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EligibilityService::class);
        $this->travelTo(now()->setTime(12, 0));
    }

    private function donor(?string $gender, ?int $daysAgo, string $column = 'last_whole_blood_donated_at'): User
    {
        return User::factory()->create([
            'gender' => $gender,
            'last_donated_at' => null,
            'last_whole_blood_donated_at' => null,
            'last_plasma_donated_at' => null,
            'last_platelet_donated_at' => null,
            $column => $daysAgo === null ? null : now()->subDays($daysAgo)->toDateString(),
        ]);
    }

    public function test_cooldown_days_come_from_config(): void
    {
        $this->assertSame(90, $this->service->cooldownDays(BloodComponentType::WHOLE_BLOOD, 'male'));
        $this->assertSame(120, $this->service->cooldownDays(BloodComponentType::WHOLE_BLOOD, 'Female'));
        $this->assertSame(120, $this->service->cooldownDays(BloodComponentType::PACKED_RBC, null), 'unknown gender uses the longer interval');
        $this->assertSame(120, $this->service->cooldownDays('whole_blood', 'other'));
        $this->assertSame(28, $this->service->cooldownDays(BloodComponentType::PLASMA, 'male'));
        $this->assertSame(14, $this->service->cooldownDays(BloodComponentType::PLATELETS, 'female'));

        config(['eligibility.cooldown_days.whole_blood.male' => 100]);
        $this->assertSame(100, $this->service->cooldownDays('whole_blood', 'male'));
    }

    public function test_boundaries_for_each_gender(): void
    {
        foreach ([['male', 90], ['female', 120], [null, 120]] as [$gender, $days]) {
            $tooSoon = $this->donor($gender, $days - 1);
            $exactly = $this->donor($gender, $days);

            $this->assertSame(1, $this->service->daysUntilEligible($tooSoon), "$gender day " . ($days - 1));
            $this->assertSame(0, $this->service->daysUntilEligible($exactly), "$gender day $days");
        }
    }

    public function test_never_donated_is_eligible(): void
    {
        $this->assertSame(0, $this->service->daysUntilEligible($this->donor('male', null)));
        $this->assertNull($this->service->nextEligibleDate($this->donor('male', null)));
    }

    public function test_the_more_recent_of_the_two_whole_blood_columns_wins(): void
    {
        $user = User::factory()->create([
            'gender' => 'male',
            'last_whole_blood_donated_at' => now()->subDays(200)->toDateString(),
            'last_donated_at' => now()->subDays(10)->toDateString(),
        ]);

        // A stale value in one column must never make a recent donor look eligible.
        $this->assertSame(80, $this->service->daysUntilEligible($user));
    }

    public function test_components_use_their_own_column_and_interval(): void
    {
        $plasma = $this->donor('male', 20, 'last_plasma_donated_at');
        $this->assertSame(8, $this->service->daysUntilEligible($plasma, BloodComponentType::PLASMA));
        // a recent plasma donation does not block whole blood in this model
        $this->assertSame(0, $this->service->daysUntilEligible($plasma, BloodComponentType::WHOLE_BLOOD));

        $platelets = $this->donor('female', 15, 'last_platelet_donated_at');
        $this->assertSame(0, $this->service->daysUntilEligible($platelets, BloodComponentType::PLATELETS));
    }

    /**
     * The PHP rule (profile page, respond button) and the SQL rule (candidate search)
     * used to disagree. Every combination must now give the same answer in both.
     */
    public function test_php_and_sql_rules_agree_for_every_combination(): void
    {
        $cases = [];
        foreach (['male', 'female', 'Male', 'other', null] as $gender) {
            foreach ([null, 1, 13, 14, 27, 28, 89, 90, 119, 120, 121, 400] as $daysAgo) {
                foreach ([
                    BloodComponentType::WHOLE_BLOOD->value => 'last_whole_blood_donated_at',
                    BloodComponentType::PLASMA->value => 'last_plasma_donated_at',
                    BloodComponentType::PLATELETS->value => 'last_platelet_donated_at',
                ] as $component => $column) {
                    $cases[] = [$this->donor($gender, $daysAgo, $column), $component, "$gender/$daysAgo/$component"];
                }
            }
        }

        foreach ([BloodComponentType::WHOLE_BLOOD, BloodComponentType::PLASMA, BloodComponentType::PLATELETS] as $component) {
            $sqlIds = $this->service->scopeEligible(User::query(), $component)->pluck('id')->all();

            foreach ($cases as [$user, $caseComponent, $label]) {
                if ($caseComponent !== $component->value) {
                    continue;
                }
                $php = $this->service->daysUntilEligible($user, $component) === 0;
                $sql = in_array($user->id, $sqlIds, true);
                $this->assertSame($php, $sql, "PHP and SQL disagree for $label");
            }
        }

        $this->assertNotEmpty($cases);
    }
}
