<?php

declare(strict_types=1);

return [
    // How long a station-displayed token may win its first bind.
    'token_rotation_seconds' => (int) env('QR_TOKEN_ROTATION_SECONDS', 60),

    // Hard lifetime of the browser session after the row is minted.
    'session_lifetime_minutes' => (int) env('QR_SESSION_LIFETIME_MINUTES', 30),

    // Anti-automation backstop for distinct phones seen from one branch/IP.
    // This is not identity control: real branches can share NAT egress. If a
    // genuine branch reaches this ceiling, the configured number is wrong and
    // must be raised; a trip is not evidence that an attack occurred.
    'distinct_phone_ip_backstop_per_branch_per_hour' => (int) env(
        'QR_DISTINCT_PHONE_IP_BACKSTOP_PER_BRANCH_PER_HOUR',
        500,
    ),
];
