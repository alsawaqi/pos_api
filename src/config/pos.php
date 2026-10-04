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

    // LAUNCH-P5 — PBKDF2-HMAC-SHA256 iterations for a NEW offline approver
    // verifier (pos_staff.pin_offline_key). Each row stores the count it was
    // made with, so changing this only affects verifiers made afterwards.
    'approver_kdf_iterations' => (int) env('POS_APPROVER_KDF_ITERATIONS', 100000),
];
