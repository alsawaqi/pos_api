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
];
