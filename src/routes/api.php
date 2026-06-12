<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\DeviceActivateController;
use App\Http\Controllers\Api\V1\Auth\DevicePairController;
use App\Http\Controllers\Api\V1\Auth\StaffPosLoginController;
use App\Http\Controllers\Api\V1\Auth\VerifyKitchenPinController;
use App\Http\Controllers\Api\V1\Auth\VerifyManagerPinController;
use App\Http\Controllers\Api\V1\Device\DeviceBranchReportController;
use App\Http\Controllers\Api\V1\Device\DeviceConfigController;
use App\Http\Controllers\Api\V1\Device\DeviceCustomersController;
use App\Http\Controllers\Api\V1\Device\DeviceDispositionController;
use App\Http\Controllers\Api\V1\Device\DeviceKitchenController;
use App\Http\Controllers\Api\V1\Device\DeviceMessagesController;
use App\Http\Controllers\Api\V1\Device\DeviceOrderNumberController;
use App\Http\Controllers\Api\V1\Device\DeviceOrdersController;
use App\Http\Controllers\Api\V1\Device\DeviceProductionsController;
use App\Http\Controllers\Api\V1\Device\DeviceShiftController;
use App\Http\Controllers\Api\V1\Device\HeartbeatController;
use App\Http\Controllers\Api\V1\Device\SyncPushController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
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

    // Single-code activation: the device exchanges one admin-generated code
    // (looked up globally by its hash) for a device_token. No kiosk_id needed on
    // the device. Throttled per-IP as the brute-force surface.
    Route::post('auth/device/activate', DeviceActivateController::class)
        ->middleware('throttle:30,1')
        ->name('device.activate');

    // Everything below requires a valid device token, throttled per-device.
    Route::middleware(['auth:pos_device', 'throttle:device-api'])->group(function (): void {
        // POS staff PIN login on a paired device (§11.1). Extra-throttled
        // (throttle:pos-login) as the PIN brute-force surface.
        Route::post('auth/pos/login', StaffPosLoginController::class)
            ->middleware('throttle:pos-login')
            ->name('pos.login');

        // P-F1 — manager PIN fallback for the device's approval gates (comps,
        // cancellations, gifts). Shares the hard per-device `pos-login`
        // limiter bucket, so the combined PIN brute-force surface stays at
        // 10/min per device across login + approval.
        Route::post('device/auth/verify-manager-pin', VerifyManagerPinController::class)
            ->middleware('throttle:pos-login')
            ->name('device.verify-manager-pin');

        // P-G1.6 — the Kitchen walk-up gate: a kitchen staff member's code
        // lets the Kitchen screen open on someone else's till session (the
        // session then runs AS the verified chef). Same brute-force bucket.
        Route::post('device/auth/verify-kitchen-pin', VerifyKitchenPinController::class)
            ->middleware('throttle:pos-login')
            ->name('device.verify-kitchen-pin');

        // §11.5 — broadcast channel authorization, on-contract at
        // /api/v1/broadcasting/auth. Broadcast::auth() runs the channel
        // callbacks in routes/channels.php against the device the guard already
        // resolved, so a device can only subscribe to its own scope.
        Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request))
            ->name('broadcasting.auth');

        Route::post('device/heartbeat', HeartbeatController::class)->name('device.heartbeat');

        // Config bundle (§11.4): full snapshot + incremental delta.
        Route::get('device/config', [DeviceConfigController::class, 'show'])->name('device.config');
        Route::get('device/config/delta', [DeviceConfigController::class, 'delta'])->name('device.config.delta');

        // Offline-sync ingestion (§10.9): idempotent batch push of device events.
        Route::post('device/sync/push', SyncPushController::class)->name('device.sync.push');

        // Live reads/writes the POS UI needs mid-sale (§11.4): the branch's
        // active orders, customer lookup by phone/plate, register a customer.
        Route::get('device/orders/active', [DeviceOrdersController::class, 'active'])->name('device.orders.active');
        Route::get('device/orders/history', [DeviceOrdersController::class, 'history'])->name('device.orders.history');
        // P-F8 — atomically allocate the next merchant-defined order
        // number (prefix + zero-padded counter) at payment time. 409
        // numbering_disabled when the merchant hasn't enabled the policy.
        Route::post('device/orders/next-number', DeviceOrderNumberController::class)->name('device.orders.next-number');
        Route::get('device/shift/current', [DeviceShiftController::class, 'current'])->name('device.shift.current');
        Route::get('device/customers/search', [DeviceCustomersController::class, 'search'])->name('device.customers.search');
        // P-F2 — customer details fetch ({id} numeric so it can never
        // shadow the literal /search segment above).
        Route::get('device/customers/{id}', [DeviceCustomersController::class, 'show'])
            ->whereNumber('id')
            ->name('device.customers.show');
        Route::post('device/customers', [DeviceCustomersController::class, 'store'])->name('device.customers.store');

        // P-F6 — the device's branch Reports dashboard: date-windowed,
        // branch-scoped sales/tender/product/discount/loyalty/consumption
        // aggregates. Who may OPEN it is the merchant's reports_positions
        // setting, enforced device-side from /device/config.
        Route::get('device/reports/branch', DeviceBranchReportController::class)->name('device.reports.branch');

        // P-G1 — kitchen production (ONLINE-ONLY: the server validates
        // fresh ingredient balances at each phase). The screen data, then
        // the two-phase batch lifecycle. Who may OPEN the Kitchen screen
        // is the merchant's kitchen_positions setting, enforced
        // device-side from /device/config. Cancel carries a manager PIN
        // verified server-side, so it shares the pos-login brute-force
        // bucket with login + verify-manager-pin.
        Route::get('device/kitchen', [DeviceKitchenController::class, 'show'])->name('device.kitchen');
        Route::post('device/productions', [DeviceProductionsController::class, 'store'])->name('device.productions.store');
        Route::post('device/productions/{uuid}/finish', [DeviceProductionsController::class, 'finish'])->name('device.productions.finish');
        Route::post('device/productions/{uuid}/cancel', [DeviceProductionsController::class, 'cancel'])
            ->middleware('throttle:pos-login')
            ->name('device.productions.cancel');

        // P-G1.5 — day-end disposition of expired cooked pieces (online-
        // only, runs right before shift close). The POST can carry a
        // manager PIN (give-away / carry-over approval), so it shares the
        // pos-login brute-force bucket.
        Route::get('device/disposition', [DeviceDispositionController::class, 'show'])->name('device.disposition.show');
        Route::post('device/disposition', [DeviceDispositionController::class, 'store'])
            ->middleware('throttle:pos-login')
            ->name('device.disposition.store');

        // P-G6 — staff-announcement read receipts. Announcements arrive
        // in /device/config (staff_messages slice); the device reports
        // who SAW them here. No PIN, no extra throttle.
        Route::post('device/messages/read', [DeviceMessagesController::class, 'read'])
            ->name('device.messages.read');
    });
});
