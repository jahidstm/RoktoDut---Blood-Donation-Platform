<?php

return [
    // How far back the dashboard looks.
    'window_months' => 12,

    // A request younger than this has not had time to be fulfilled, so it is left out of
    // the fulfilment rate (counting it as a failure would make recent weeks look bad).
    'mature_after_days' => 7,

    'weeks_shown' => 12,

    // With fewer real requests than this the dashboard opens on demo data (with a banner)
    // instead of a near-empty page. The admin can switch back with ?source=real.
    'min_real_requests' => 30,
];
