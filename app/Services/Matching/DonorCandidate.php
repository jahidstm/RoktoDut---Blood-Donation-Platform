<?php

namespace App\Services\Matching;

use App\Models\User;

final class DonorCandidate
{
    public function __construct(
        public readonly User $user,
        /** Null when the request has no coordinates (district fallback): distance is unknown, not 9999. */
        public readonly ?float $distanceKm,
        /** False when the donor's group is compatible but not the patient's exact group. */
        public readonly bool $isIdenticalGroup,
    ) {}
}
