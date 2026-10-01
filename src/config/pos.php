<?php

return [
    'business_timezone' => env('POS_BUSINESS_TIMEZONE', 'Asia/Muscat'),

    // LAUNCH-P1 decision 1a — an activation code works only on the physical
    // device it was made for (matched by its hardware serial number).
    //   off     — no serial/app checks (legacy APKs that send only the code).
    //   report  — allow, but record every mismatch for the admin to review.
    //   enforce — refuse a missing or different serial, or the wrong app.
    // Any other value is treated as `enforce` (fail closed).
    'device_serial_binding' => env('POS_DEVICE_SERIAL_BINDING', 'enforce'),
];
