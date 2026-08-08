<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Historical Stranded-Event Quarantine Floor
    |--------------------------------------------------------------------------
    |
    | The recovery sweep may only redispatch sync events created after this
    | one-time operator-selected id. There is deliberately no default: a
    | missing or invalid value keeps the sweep fail-closed.
    |
    */
    'stranded_sweep_after_id' => env('SYNC_STRANDED_SWEEP_AFTER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Scheduled Recovery Activation Gate
    |--------------------------------------------------------------------------
    |
    | The operator first verifies the immutable history floor with scheduled
    | recovery disabled. Manual bounded sweeps remain available during that
    | canary stage; only the every-minute schedule is controlled here.
    |
    */
    'stranded_sweep_enabled' => filter_var(
        env('SYNC_STRANDED_SWEEP_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),
];
