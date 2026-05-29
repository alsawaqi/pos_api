<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\DevicePairController;
use App\Http\Controllers\Api\V1\Device\HeartbeatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Device API (v1) — blueprint §11.1 + §11.4
|--------------------------------------------------------------------------
| The device-facing POS backend. All paths are prefixed /api/v1 (the `api`
| group prefix from bootstrap/app.php + the v1 group here).
|
| Auth model: a device pairs once (kiosk_id + one-time activation token)
| and receives a long-lived `device_token`, stored on pos_devices. Every
| later request carries it as `Authorization: Bearer <device_token>` and is
| resolved by the custom `pos_device` guard (see AppServiceProvider).
|
| POS-staff PIN login + the rest of §11.4 (config bundle, sync push, orders,
| customers, shifts, expenses) land in later Phase 8 sub-phases.
*/

Route::prefix('v1')->group(function (): void {
    // Pairing is unauthenticated by the device guard (the device has no
    // token yet) — it authenticates via the one-time activation token.
    Route::post('auth/device/pair', DevicePairController::class)->name('device.pair');

    // Everything below requires a valid device token.
    Route::middleware('auth:pos_device')->group(function (): void {
        Route::post('device/heartbeat', HeartbeatController::class)->name('device.heartbeat');
    });
});
