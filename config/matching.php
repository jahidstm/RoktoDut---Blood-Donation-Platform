<?php

/*
|--------------------------------------------------------------------------
| Donor matching rules
|--------------------------------------------------------------------------
|
| Used by App\Services\Matching\* and the three dispatch paths
| (DonorMatchingService, SmartBloodRequestBroadcastJob, SosBloodRequestBroadcastJob).
|
| ⚠️ Compatibility is medical policy, NOT advice. The hospital / blood bank always
| does the final cross-match. Confirm this table with the transfusion service you
| work with before running it against real patients.
|
*/

return [

    /*
    | Which donor blood groups may be notified for each requested component.
    |
    |  identical : only the patient's exact ABO + Rh group.
    |  red_cell  : red-cell compatible groups (BloodGroup::compatibleDonors()),
    |              exact group ranked first. Only valid for red-cell products.
    |
    | Whole blood also contains donor plasma, so it stays ABO-identical. Plasma
    | compatibility runs the opposite way to red cells, and platelets are
    | preferably ABO-identical, so both stay identical here.
    */
    'compatibility' => [
        'whole_blood' => 'identical',
        'packed_rbc' => 'red_cell',
        'platelets' => 'identical',
        'plasma' => 'identical',
    ],

    /*
    | Score points subtracted from a donor whose group is compatible but not
    | identical, so exact matches are contacted first when both exist.
    */
    'compatible_group_penalty' => 30,

    /*
    | Smoothed response rate: (accepted + strength * mean) / (answered + strength).
    | A donor with no history gets `mean` instead of 0, so new donors are not
    | pushed to the bottom. Both numbers are guesses until real data exists;
    | re-estimate them from donor_response_logs once there are enough rows.
    */
    'response_rate_prior' => [
        'mean' => 0.3,
        'strength' => 5,
    ],
];
