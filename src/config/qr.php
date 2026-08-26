<?php

declare(strict_types=1);

return [
    // How long a station-displayed token may win its first bind.
    'token_rotation_seconds' => (int) env('QR_TOKEN_ROTATION_SECONDS', 60),

    // Hard lifetime of the browser session after the row is minted.
    'session_lifetime_minutes' => (int) env('QR_SESSION_LIFETIME_MINUTES', 30),
];
