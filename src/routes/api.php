<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\DevicePairController;
use App\Http\Controllers\Api\V1\Auth\StaffPosLoginController;
use App\Http\Controllers\Api\V1\Device\DeviceConfigController;
use App\Http\Controllers\Api\V1\Device\HeartbeatController;
use App\Http\Controllers\Api\V1\Device\SyncPushController;
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
    // Throttled hard (per-IP + per-kiosk) as the brute-force surface.
    Route::post('auth/device/pair', DevicePairController::class)
        ->middleware('throttle:device-pair')
        ->name('device.pair');

    // Everything below requires a valid device token, throttled per-device.
    Route::middleware(['auth:pos_device', 'throttle:device-api'])->group(function (): void {
        // POS staff PIN login on a paired device (§11.1). Extra-throttled
        // (throttle:pos-login) as the PIN brute-force surface.
        Route::post('auth/pos/login', StaffPosLoginController::class)
            ->middleware('throttle:pos-login')
            ->name('pos.login');

        Route::post('device/heartbeat', HeartbeatController::class)->name('device.heartbeat');

        // Config bundle (§11.4): full snapshot + incremental delta.
        Route::get('device/config', [DeviceConfigController::class, 'show'])->name('device.config');
        Route::get('device/config/delta', [DeviceConfigController::class, 'delta'])->name('device.config.delta');

        // Offline-sync ingestion (§10.9): idempotent batch push of device events.
        Route::post('device/sync/push', SyncPushController::class)->name('device.sync.push');
    });
});
