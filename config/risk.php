<?php

/*
 * Rules that flag a blood request for a HUMAN to look at. Nothing here blocks, hides or
 * deletes anything: a real emergency that gets stuck is worse than a spam request that
 * gets through. Every weight is a judgement call, not a fitted value, and there is no
 * labelled data yet to tune them. The review queue records admin decisions so there will be.
 */
return [
    // A request is queued for review when its signals add up to this much.
    'review_threshold' => 3,

    // A dismissed flag re-opens only if the score has grown by at least this much since the review.
    'reopen_after_increase' => 2,

    'phone_burst' => ['window_hours' => 24, 'min_requests' => 3, 'weight' => 2],
    'ip_burst' => ['window_hours' => 24, 'min_requests' => 4, 'weight' => 2],
    'duplicate_text' => ['window_days' => 7, 'min_length' => 15, 'weight' => 2],
    'spam_reports' => ['min_reports' => 2, 'weight' => 1, 'strong_min_reports' => 3, 'strong_weight' => 3],
    'managed_reports' => ['min_reports' => 3, 'weight' => 3],
    'requester_strikes' => ['min_strikes' => 1, 'weight' => 1],
    'implausible_units' => ['min_units' => 8, 'weight' => 1],
    'phone_pattern' => ['weight' => 1],
];
