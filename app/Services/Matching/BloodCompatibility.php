<?php

namespace App\Services\Matching;

use App\Enums\BloodComponentType;
use App\Enums\BloodGroup;

/**
 * Which donor blood groups can be asked for a given request.
 *
 * A fixed lookup, never a prediction. Policy per component lives in
 * config/matching.php; the red-cell table itself is BloodGroup::compatibleDonors().
 */
class BloodCompatibility
{
    /**
     * Donor groups to notify, the patient's own group first.
     *
     * @return list<string> empty when the patient's group is unknown or invalid
     */
    public function donorGroupsFor(mixed $recipientGroup, mixed $component): array
    {
        $recipient = $this->toBloodGroup($recipientGroup);

        if ($recipient === null) {
            return [];
        }

        $policy = config('matching.compatibility.' . $this->componentValue($component), 'identical');

        if ($policy !== 'red_cell') {
            return [$recipient->value];
        }

        $others = array_values(array_filter(
            $recipient->compatibleDonors(),
            fn (BloodGroup $g) => $g !== $recipient
        ));

        return [$recipient->value, ...array_map(fn (BloodGroup $g) => $g->value, $others)];
    }

    public function isIdentical(mixed $donorGroup, mixed $recipientGroup): bool
    {
        $donor = $this->toBloodGroup($donorGroup);

        return $donor !== null && $donor === $this->toBloodGroup($recipientGroup);
    }

    private function toBloodGroup(mixed $group): ?BloodGroup
    {
        if ($group instanceof BloodGroup) {
            return $group;
        }

        return BloodGroup::tryFrom(strtoupper(str_replace(' ', '', (string) $group)));
    }

    private function componentValue(mixed $component): string
    {
        if ($component instanceof BloodComponentType) {
            return $component->value;
        }

        return BloodComponentType::tryFrom((string) $component)?->value
            ?? BloodComponentType::WHOLE_BLOOD->value;
    }
}
