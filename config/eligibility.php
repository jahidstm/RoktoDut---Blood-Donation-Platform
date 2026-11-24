<?php

/*
|--------------------------------------------------------------------------
| Donation eligibility rules (single source of truth)
|--------------------------------------------------------------------------
|
| Minimum days between two donations of the same kind. Before this file the
| numbers were repeated in User.php, DonorMatchingService and two broadcast
| jobs, and they disagreed (90/120 by gender in one place, 120 for everyone
| in the others).
|
| ⚠️ These are product defaults, NOT medical advice. Before this runs against
| real donors, confirm them against the guideline of the blood-transfusion
| authority / hospital you work with, and cite it here.
|   Source: [TODO: add the guideline name, edition and page]
|
| When the donor's gender is unknown the LONGER interval is used, because
| wrongly skipping a donor is safe while wrongly notifying one is not.
|
*/

return [
    'cooldown_days' => [
        // whole_blood also applies to packed_rbc
        'whole_blood' => [
            'male' => 90,
            'female' => 120,
            'unknown' => 120,
        ],
        'plasma' => 28,
        'platelets' => 14,
    ],
];
