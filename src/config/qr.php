<?php

declare(strict_types=1);

return [
    // How long a station-displayed token may win its first bind.
    'token_rotation_seconds' => (int) env('QR_TOKEN_ROTATION_SECONDS', 60),

    // Hard lifetime of the browser session after the row is minted.
    'session_lifetime_minutes' => (int) env('QR_SESSION_LIFETIME_MINUTES', 30),

    // A dine-in tab survives quick-QR rotations and browser replacement, but
    // is bounded so abandoned tables eventually enter attended recovery.
    'dine_in_session_lifetime_hours' => (int) env('QR_DINE_IN_SESSION_LIFETIME_HOURS', 6),

    // Anti-automation backstop for distinct phones seen from one branch/IP.
    // This is not identity control: real branches can share NAT egress. If a
    // genuine branch reaches this ceiling, the configured number is wrong and
    // must be raised; a trip is not evidence that an attack occurred.
    'distinct_phone_ip_backstop_per_branch_per_hour' => (int) env(
        'QR_DISTINCT_PHONE_IP_BACKSTOP_PER_BRANCH_PER_HOUR',
        500,
    ),

    // How long a station owns the frozen sale amount after a successful claim.
    'charge_claim_seconds' => (int) env('QR_CHARGE_CLAIM_SECONDS', 180),

    // An attended till needs longer than a station tap to select a tender and
    // take cash/card, but still enters ambiguity if staff abandon the sheet.
    'settlement_claim_seconds' => (int) env('QR_SETTLEMENT_CLAIM_SECONDS', 300),

    // Audit-release margin beyond the claim deadline so the sweeper does not
    // stamp cancellation while an on-time station result is still arriving.
    'charge_sweep_grace_seconds' => (int) env('QR_CHARGE_SWEEP_GRACE_SECONDS', 30),

    // Fail-closed deploy gate for the every-minute stale-charge sweeper.
    'charge_sweep_enabled' => filter_var(
        env('QR_CHARGE_SWEEP_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    // Interim estate-wide exemption for bolted-down stations that cannot get
    // GPS indoors. Default false; replace with an admin-provisioned device flag.
    'station_geofence_exempt' => filter_var(
        env('QR_STATION_GEOFENCE_EXEMPT', false),
        FILTER_VALIDATE_BOOL,
    ),
];
