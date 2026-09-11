<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\DeviceActivateController;
use App\Http\Controllers\Api\V1\Auth\DevicePairController;
use App\Http\Controllers\Api\V1\Auth\StaffPosLoginController;
use App\Http\Controllers\Api\V1\Auth\VerifyKitchenPinController;
use App\Http\Controllers\Api\V1\Auth\VerifyManagerPinController;
use App\Http\Controllers\Api\V1\Device\DeviceBranchDevicesController;
use App\Http\Controllers\Api\V1\Device\DeviceBranchReportController;
use App\Http\Controllers\Api\V1\Device\DeviceConfigController;
use App\Http\Controllers\Api\V1\Device\DeviceCustomersController;
use App\Http\Controllers\Api\V1\Device\DeviceDispositionController;
use App\Http\Controllers\Api\V1\Device\DeviceKitchenController;
use App\Http\Controllers\Api\V1\Device\DeviceKitchenPrintController;
use App\Http\Controllers\Api\V1\Device\DeviceMessagesController;
use App\Http\Controllers\Api\V1\Device\DeviceOrderNumberController;
use App\Http\Controllers\Api\V1\Device\DeviceOrdersController;
use App\Http\Controllers\Api\V1\Device\DeviceProductionsController;
use App\Http\Controllers\Api\V1\Device\DeviceQrAcceptedRoundsController;
use App\Http\Controllers\Api\V1\Device\DeviceQrAwaitingOrdersController;
use App\Http\Controllers\Api\V1\Device\DeviceQrCheckoutController;
use App\Http\Controllers\Api\V1\Device\DeviceQrClaimChargeController;
use App\Http\Controllers\Api\V1\Device\DeviceQrClaimSettlementController;
use App\Http\Controllers\Api\V1\Device\DeviceQrClearTableController;
use App\Http\Controllers\Api\V1\Device\DeviceQrConfirmRoundController;
use App\Http\Controllers\Api\V1\Device\DeviceQrFallbackToCounterController;
use App\Http\Controllers\Api\V1\Device\DeviceQrOpenTableController;
use App\Http\Controllers\Api\V1\Device\DeviceQrPendingOrdersController;
use App\Http\Controllers\Api\V1\Device\DeviceQrRejectRoundController;
use App\Http\Controllers\Api\V1\Device\DeviceQrReleaseChargeController;
use App\Http\Controllers\Api\V1\Device\DeviceQrReopenPaymentController;
use App\Http\Controllers\Api\V1\Device\DeviceQrRotateController;
use App\Http\Controllers\Api\V1\Device\DeviceQrTableBoardController;
use App\Http\Controllers\Api\V1\Device\DeviceQrTableRoundController;
use App\Http\Controllers\Api\V1\Device\DeviceShiftController;
use App\Http\Controllers\Api\V1\Device\DeviceStaffRoundReviewController;
use App\Http\Controllers\Api\V1\Device\DeviceTableBoardController;
use App\Http\Controllers\Api\V1\Device\DeviceTableClaimOwnerController;
use App\Http\Controllers\Api\V1\Device\DeviceTableDetailController;
use App\Http\Controllers\Api\V1\Device\DeviceTableFeedController;
use App\Http\Controllers\Api\V1\Device\DeviceTableReleaseCredentialController;
use App\Http\Controllers\Api\V1\Device\DeviceTableSearchController;
use App\Http\Controllers\Api\V1\Device\DeviceTableSessionController;
use App\Http\Controllers\Api\V1\Device\DeviceTransfersController;
use App\Http\Controllers\Api\V1\Device\HeartbeatController;
use App\Http\Controllers\Api\V1\Device\SyncPushController;
use App\Http\Controllers\Api\V1\PublicQr\QrBindController;
use App\Http\Controllers\Api\V1\PublicQr\QrCheckoutController;
use App\Http\Controllers\Api\V1\PublicQr\QrMenuController;
use App\Http\Controllers\Api\V1\PublicQr\QrQuoteController;
use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Http\Controllers\Api\V1\PublicQr\QrTableBindController;
use App\Http\Controllers\Api\V1\PublicQr\QrTableFinishController;
use App\Http\Controllers\Api\V1\PublicQr\QrTableMenuController;
use App\Http\Controllers\Api\V1\PublicQr\QrTableRoundController;
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

    Route::post('public/qr/bind', QrBindController::class)
        ->middleware('throttle:qr-bind')
        ->name('public.qr.bind');
    Route::get('public/qr/menu', QrMenuController::class)
        ->middleware(['throttle:qr-read', 'qr.session'])
        ->name('public.qr.menu');
    Route::post('public/qr/quote', QrQuoteController::class)
        ->middleware(['throttle:qr-quote', 'qr.session'])
        ->name('public.qr.quote');
    Route::post('public/qr/checkout', QrCheckoutController::class)
        ->middleware(['throttle:qr-checkout', 'qr.session'])
        ->name('public.qr.checkout');
    Route::get('public/qr/status', QrStatusController::class)
        ->middleware(['throttle:qr-read', 'qr.session:include-closed'])
        ->name('public.qr.status');
    Route::get('public/qr/table-menu', QrTableMenuController::class)
        ->middleware('throttle:qr-table-read')
        ->name('public.qr.table-menu');
    Route::post('public/qr/table-bind', QrTableBindController::class)
        ->middleware('throttle:qr-table-bind')
        ->name('public.qr.table-bind');
    Route::post('public/qr/table-round', QrTableRoundController::class)
        ->middleware(['throttle:qr-dine-in-round', 'qr.session'])
        ->name('public.qr.table-round');
    Route::post('public/qr/table-finish', QrTableFinishController::class)
        ->middleware(['throttle:qr-dine-in-finish', 'qr.session'])
        ->name('public.qr.table-finish');

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
        Route::post('device/qr/rotate', DeviceQrRotateController::class)->name('device.qr.rotate');
        Route::post('device/qr/open-table', DeviceQrOpenTableController::class)
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.open-table');
        Route::get('device/qr/table-board', DeviceQrTableBoardController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.qr.table-board');
        Route::post('device/qr/confirm-round', DeviceQrConfirmRoundController::class)
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.confirm-round');
        Route::post('device/qr/reject-round', DeviceQrRejectRoundController::class)
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.reject-round');
        Route::get('device/qr/table-round/{round_id}', DeviceQrTableRoundController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.qr.table-round');
        Route::get('device/qr/accepted-rounds', DeviceQrAcceptedRoundsController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.qr.accepted-rounds');
        Route::post('device/qr/clear-table', DeviceQrClearTableController::class)
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.clear-table');
        Route::post('device/qr/reopen-payment', DeviceQrReopenPaymentController::class)
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.reopen-payment');
        Route::post('device/tables/{uuid}/release-credential', DeviceTableReleaseCredentialController::class)
            ->middleware('throttle:qr-table-device-write')->name('device.tables.release-credential');
        Route::get('device/tables/board', DeviceTableBoardController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.tables.board');
        Route::get('device/tables/{tableId}/detail', DeviceTableDetailController::class)
            ->whereNumber('tableId')->middleware('throttle:qr-table-device-read')
            ->name('device.tables.detail');
        Route::get('device/tables/feed', DeviceTableFeedController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.tables.feed');
        Route::get('device/tables/search', DeviceTableSearchController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.tables.search');
        Route::post('device/qr/pending-orders/{uuid}/to-counter', [DeviceQrPendingOrdersController::class, 'toCounter'])
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.pending-orders.to-counter');
        Route::post('device/qr/pending-orders/{uuid}/items', [DeviceQrPendingOrdersController::class, 'appendItems'])
            ->middleware('throttle:qr-table-device-write')
            ->name('device.qr.pending-orders.items');
        Route::get('device/qr/pending-orders', [DeviceQrPendingOrdersController::class, 'index'])
            ->middleware('throttle:qr-table-device-read')
            ->name('device.qr.pending-orders');
        Route::get('device/qr/awaiting-orders', DeviceQrAwaitingOrdersController::class)
            ->middleware('throttle:qr-station-read')
            ->name('device.qr.awaiting-orders');
        Route::post('device/qr/claim-charge', DeviceQrClaimChargeController::class)
            ->name('device.qr.claim-charge');
        Route::get('device/qr/orders/{uuid}/checkout', DeviceQrCheckoutController::class)
            ->middleware('throttle:qr-table-device-read')
            ->name('device.qr.checkout');
        Route::post('device/qr/claim-settlement', DeviceQrClaimSettlementController::class)
            ->middleware('throttle:qr-settlement-claim')
            ->name('device.qr.claim-settlement');
        Route::post('device/qr/release-charge', DeviceQrReleaseChargeController::class)
            ->name('device.qr.release-charge');
        Route::post('device/qr/fallback-to-counter', DeviceQrFallbackToCounterController::class)
            ->name('device.qr.fallback-to-counter');

        Route::post('device/tables/open', DeviceTableSessionController::class)
            ->defaults('table_operation', 'open')
            ->middleware('throttle:qr-table-device-write')->name('device.tables.open');
        foreach (['round', 'move', 'join', 'close', 'cancel_line'] as $tableOperation) {
            Route::post('device/tables/{uuid}/'.str_replace('_', '-', $tableOperation), DeviceTableSessionController::class)
                ->defaults('table_operation', $tableOperation)
                ->middleware('throttle:qr-table-device-write')->name('device.tables.'.$tableOperation);
        }
        Route::post('device/tables/{uuid}/claim-owner', DeviceTableClaimOwnerController::class)
            ->middleware('throttle:qr-table-device-write')->name('device.tables.claim-owner');
        Route::post('device/tables/{uuid}/rounds/{roundId}/confirm', [DeviceStaffRoundReviewController::class, 'confirm'])
            ->whereNumber('roundId')->middleware('throttle:qr-table-device-write')->name('device.tables.rounds.confirm');
        Route::post('device/tables/{uuid}/rounds/{roundId}/reject', [DeviceStaffRoundReviewController::class, 'reject'])
            ->whereNumber('roundId')->middleware('throttle:qr-table-device-write')->name('device.tables.rounds.reject');
        Route::post('device/kitchen/claim-print', [DeviceKitchenPrintController::class, 'claim'])
            ->name('device.kitchen.claim-print');
        Route::post('device/kitchen/print-result', [DeviceKitchenPrintController::class, 'result'])
            ->name('device.kitchen.print-result');

        // Config bundle (§11.4): full snapshot + incremental delta.
        Route::get('device/config', [DeviceConfigController::class, 'show'])->name('device.config');
        Route::get('device/config/delta', [DeviceConfigController::class, 'delta'])->name('device.config.delta');

        // Offline-sync ingestion (§10.9): idempotent batch push of device events.
        Route::post('device/sync/push', SyncPushController::class)->name('device.sync.push');

        // Live reads/writes the POS UI needs mid-sale (§11.4): the branch's
        // active orders, customer lookup by phone/plate, register a customer.
        Route::get('device/orders/active', [DeviceOrdersController::class, 'active'])->name('device.orders.active');
        Route::get('device/orders/history', [DeviceOrdersController::class, 'history'])->name('device.orders.history');

        // Device-to-device order transfer: the picker's device list, the
        // receiving device's inbox, and the atomic claim. The SEND leg rides
        // the order.transfer sync event (idempotent, upserts the held mirror).
        Route::get('device/branch-devices', DeviceBranchDevicesController::class)->name('device.branch-devices');
        Route::get('device/transfers/incoming', [DeviceTransfersController::class, 'incoming'])->name('device.transfers.incoming');
        Route::post('device/transfers/{uuid}/claim', [DeviceTransfersController::class, 'claim'])->name('device.transfers.claim');
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
