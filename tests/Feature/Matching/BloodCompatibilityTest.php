<?php

namespace Tests\Feature\Matching;

use App\Enums\BloodComponentType;
use App\Enums\BloodGroup;
use App\Services\Matching\BloodCompatibility;
use Tests\TestCase;

class BloodCompatibilityTest extends TestCase
{
    /**
     * Red-cell compatibility written out independently of BloodGroup::compatibleDonors(),
     * so a typo in the enum is caught: the donor must not carry an ABO antigen the
     * recipient lacks, and an Rh-negative recipient only receives Rh-negative cells.
     */
    public function test_packed_rbc_follows_red_cell_rules_for_all_64_pairs(): void
    {
        $service = new BloodCompatibility();

        $abo = fn (string $g) => rtrim($g, '+-');
        $antigens = fn (string $abo) => match ($abo) {
            'O' => [],
            'A' => ['A'],
            'B' => ['B'],
            'AB' => ['A', 'B'],
        };

        foreach (BloodGroup::cases() as $recipient) {
            $allowed = $service->donorGroupsFor($recipient, BloodComponentType::PACKED_RBC);

            foreach (BloodGroup::cases() as $donor) {
                $r = $recipient->value;
                $d = $donor->value;
                $aboOk = array_diff($antigens($abo($d)), $antigens($abo($r))) === [];
                $rhOk = str_ends_with($r, '+') || str_ends_with($d, '-');

                $this->assertSame($aboOk && $rhOk, in_array($d, $allowed, true), "donor {$d} -> recipient {$r}");
            }

            $this->assertSame($recipient->value, $allowed[0], 'the patient group is always first');
        }
    }

    public function test_whole_blood_plasma_and_platelets_are_identical_only(): void
    {
        $service = new BloodCompatibility();

        foreach ([BloodComponentType::WHOLE_BLOOD, BloodComponentType::PLASMA, BloodComponentType::PLATELETS] as $component) {
            foreach (BloodGroup::cases() as $g) {
                $this->assertSame([$g->value], $service->donorGroupsFor($g, $component), "{$component->value} / {$g->value}");
            }
        }

        // a missing component means whole blood
        $this->assertSame(['A+'], $service->donorGroupsFor('A+', null));
    }

    public function test_input_is_normalised_and_unknown_groups_match_nobody(): void
    {
        $service = new BloodCompatibility();

        $this->assertSame(['AB-'], $service->donorGroupsFor('ab -', 'whole_blood'));
        $this->assertSame([], $service->donorGroupsFor('unknown', 'packed_rbc'));
        $this->assertSame([], $service->donorGroupsFor(null, 'packed_rbc'));
        $this->assertTrue($service->isIdentical('O+', BloodGroup::O_POS));
        $this->assertFalse($service->isIdentical('O-', 'O+'));
    }

    public function test_policy_is_read_from_config(): void
    {
        config(['matching.compatibility.whole_blood' => 'red_cell']);

        $this->assertContains('O-', (new BloodCompatibility())->donorGroupsFor('A+', 'whole_blood'));
    }
}
