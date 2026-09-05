<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Atomically reserves an open/held dine-in QR order for an attended till.
 *
 * This deliberately reuses the shipped charge-provenance columns so every
 * downstream live/ambiguous-claim guard remains authoritative.
 */
final class ClaimQrSettlementAction
{
    /** @var list<string> */
    private const CHARGE_FIELDS = [
        'charge_device_id',
        'charge_amount_baisas',
        'charge_roundup_amount_baisas',
        'charge_claimed_at',
        'charge_deadline_at',
        'charge_outcome',
    ];

    public function __construct(
        private readonly GeofenceGuard $geofence,
        private readonly QrChargeRecoveryGuard $recovery,
        private readonly EnsureTableSessionForQrSessionAction $seatings,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /**
     * @param array{
     *   order_uuid: string,
     *   gps?: array{lat: int|float|string, lng: int|float|string}|null
     * } $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload): array
    {
        if (! $this->recovery->isAttendedDevice($device)) {
            throw new QrChargeException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may claim settlement.',
            );
        }

        $orderUuid = trim((string) $payload['order_uuid']);
        $gps = is_array($payload['gps'] ?? null)
            ? ['lat' => (float) $payload['gps']['lat'], 'lng' => (float) $payload['gps']['lng']]
            : null;

        // Locating the primary key before the transaction lets every writer
        // that has an order use the canonical order -> session lock order.
        // The row is fully re-read, tenant-checked and locked below.
        $orderId = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->value('id');
        if ($orderId === null) {
            throw new QrChargeException('order_not_found', 404, 'The order was not found.');
        }

        $result = DB::transaction(function () use ($device, $orderId, $orderUuid, $gps): array|QrChargeException {
            $now = now();
            $order = Order::query()
                ->whereKey((int) $orderId)
                ->where('uuid', $orderUuid)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }
            if (! $this->isDineInQrOrder($order)) {
                throw new QrChargeException(
                    'qr_order_not_settleable',
                    409,
                    'This order is not an attended dine-in QR settlement.',
                );
            }

            $session = $this->lockSettlementSession($order, $device);

            // A lost claim response is safe to replay only to the same live
            // holder. Every competing till or station is refused before money.
            if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                if ($this->hasLiveClaim($order, $now)) {
                    if ($order->charge_outcome === null
                        && $order->charge_device_id !== null
                        && (int) $order->charge_device_id === (int) $device->getKey()) {
                        $this->assertHeldClaimSession($session, $now);

                        return $this->present($order, true);
                    }

                    throw new QrChargeException(
                        'charge_already_claimed',
                        409,
                        'The order already has a live settlement claim.',
                    );
                }

                if ($this->recovery->isAmbiguousCharge($order, $now)
                    || ! $this->hasNoChargeProvenance($order)) {
                    throw new QrChargeException(
                        'qr_charge_recovery_required',
                        409,
                        'This order has charge evidence requiring attended recovery.',
                    );
                }

                throw new QrChargeException(
                    'qr_order_not_settleable',
                    409,
                    'Reopen this QR order before starting a new settlement attempt.',
                );
            }

            if (! in_array($order->status, [Order::STATUS_OPEN, Order::STATUS_HELD], true)) {
                throw new QrChargeException(
                    'qr_order_not_settleable',
                    409,
                    'This QR order is not open for settlement.',
                );
            }
            if (! $this->hasNoChargeProvenance($order)) {
                throw new QrChargeException(
                    'qr_charge_recovery_required',
                    409,
                    'This order has charge evidence requiring attended recovery.',
                );
            }

            $this->assertSessionAdmission($session, $order, $now);

            // Pending staff-confirmation rows are not accepted money. Make
            // them terminal inside this transaction before deciding whether
            // any accepted amount exists to reserve.
            $acceptedRounds = QrOrderRound::query()
                ->where('order_id', $order->getKey())
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->count();
            $pendingRounds = QrOrderRound::query()
                ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION);
            if ($session === null) {
                $pendingRounds->where('order_id', $order->getKey());
            } else {
                $pendingRounds->where(function ($query) use ($order, $session): void {
                    $query->where('order_id', $order->getKey())
                        ->orWhere('qr_session_id', $session->getKey());
                });
            }

            $pendingRounds->update([
                'status' => QrOrderRound::STATUS_REJECTED,
                'resolved_at' => $now,
                'resolved_by_device_id' => $device->getKey(),
                'confirm_payload' => null,
                'updated_at' => $now,
            ]);
            if ($acceptedRounds === 0
                && ! $this->isAcceptedRoundlessSafeFallbackOrphan($session, $order)) {
                // Return instead of throwing so the terminal-safe pending
                // cleanup commits before the controller emits the refusal.
                return new QrChargeException(
                    'qr_order_not_settleable',
                    409,
                    'Confirm at least one QR round before taking payment.',
                );
            }

            $this->enforceClaimGeofence($device, $gps);
            $claimSeconds = max(1, (int) config('qr.settlement_claim_seconds', 300));
            $wasOpen = $order->status === Order::STATUS_OPEN;
            $order->update([
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'charge_device_id' => $device->getKey(),
                'charge_amount_baisas' => Money::toBaisas($order->grand_total),
                'charge_roundup_amount_baisas' => null,
                'charge_claimed_at' => $now,
                'charge_deadline_at' => $now->copy()->addSeconds($claimSeconds),
                'charge_outcome' => null,
            ]);
            // A safe orphan has already passed through fallback-to-counter.
            // Preserve its expired/null credential rather than reviving a
            // browser session merely because an attended till reserved it.
            if ($session !== null && $session->status !== QrSession::STATUS_EXPIRED) {
                $session->update([
                    'status' => QrSession::STATUS_ORDERED,
                    'last_seen_at' => $now,
                ]);
            }

            if ($wasOpen && $session !== null) {
                $seating = $this->seatings->handle($session, $order, $device);
                TableSession::query()
                    ->whereKey($seating->id)
                    ->where('company_id', (int) $order->company_id)
                    ->where('branch_id', (int) $order->branch_id)
                    ->where('table_id', (int) $order->table_id)
                    ->where('status', TableSession::STATUS_OPEN)
                    ->update(['status' => TableSession::STATUS_BILLING, 'billing_at' => $now]);
                $this->journal->handle($seating, 'billing', ['order_uuid' => (string) $order->uuid], (int) $device->id, $now);
            }

            return $this->present($order->refresh(), false);
        }, 5);

        if ($result instanceof QrChargeException) {
            throw $result;
        }

        Log::info('qr-settlement claim', [
            'order_uuid' => $result['order_uuid'],
            'device_id' => (int) $device->getKey(),
            'charge_amount_baisas' => $result['charge_amount_baisas'],
            'already_claimed_by_this_device' => $result['already_claimed_by_this_device'],
        ]);

        return $result;
    }

    private function isDineInQrOrder(Order $order): bool
    {
        return $order->source === Order::SOURCE_QR_WEB
            && $order->order_type === 'dine_in'
            && $order->table_id !== null;
    }

    private function lockSettlementSession(Order $order, Device $device): ?QrSession
    {
        if ($order->qr_session_id === null) {
            // Hard-deleting the opening device NULLs this FK. Only the held
            // fallback shape with a temp or legacy receipt reference, or a
            // claim already written on it, can proceed without credentials.
            if ($this->isFallbackHeldShape($order)
                || ($order->status === Order::STATUS_AWAITING_PAYMENT
                    && $order->charge_claimed_at !== null)) {
                return null;
            }

            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a dine-in session in this branch.',
            );
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->where('table_id', (int) $order->table_id)
            ->lockForUpdate()
            ->first();
        if ($session === null) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a dine-in session in this branch.',
            );
        }

        return $session;
    }

    private function assertSessionAdmission(
        ?QrSession $session,
        Order $order,
        CarbonInterface $at,
    ): void {
        if ($this->isSafeFallbackHeldOrder($order)
            && ($session === null || $session->status === QrSession::STATUS_EXPIRED)) {
            return;
        }
        if ($session === null) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a dine-in session in this branch.',
            );
        }
        if ($session->expires_at === null || $session->expires_at->lte($at)) {
            throw new QrChargeException(
                'qr_session_expired',
                409,
                'This table session has expired and requires attended recovery.',
            );
        }

        $expected = $order->status === Order::STATUS_OPEN
            ? QrSession::STATUS_ACTIVE
            : QrSession::STATUS_ORDERED;
        if ($session->status !== $expected) {
            throw new QrChargeException(
                'qr_session_not_settleable',
                409,
                'This table session is not open for attended settlement.',
            );
        }
    }

    private function assertHeldClaimSession(?QrSession $session, CarbonInterface $at): void
    {
        // A fallbacked orphan deliberately keeps an expired/deleted session.
        // Same-holder replay remains idempotent without reviving it.
        if ($session === null || $session->status === QrSession::STATUS_EXPIRED) {
            return;
        }
        if ($session->expires_at === null || $session->expires_at->lte($at)) {
            throw new QrChargeException(
                'qr_session_expired',
                409,
                'This table session has expired and requires attended recovery.',
            );
        }
        if ($session->status !== QrSession::STATUS_ORDERED) {
            throw new QrChargeException(
                'qr_session_not_settleable',
                409,
                'This table session no longer holds an attended settlement claim.',
            );
        }
    }

    private function hasLiveClaim(Order $order, CarbonInterface $at): bool
    {
        return Order::query()
            ->whereKey($order->getKey())
            ->withLiveClaim($at)
            ->exists();
    }

    private function hasNoChargeProvenance(Order $order): bool
    {
        foreach (self::CHARGE_FIELDS as $field) {
            if ($order->getRawOriginal($field) !== null) {
                return false;
            }
        }

        return true;
    }

    private function isAcceptedRoundlessSafeFallbackOrphan(
        ?QrSession $session,
        Order $order,
    ): bool {
        // Hard-deleting the opening station cascades the session and its round
        // evidence after S1 has already preserved the payable order. Admit
        // only the pre-existing fail-closed fallback shape with frozen money.
        return $session === null
            && $this->isSafeFallbackHeldOrder($order)
            && Money::toBaisas($order->grand_total) > 0;
    }

    private function isSafeFallbackHeldOrder(Order $order): bool
    {
        return $this->isFallbackHeldShape($order)
            && $this->hasNoChargeProvenance($order);
    }

    private function hasCounterReference(Order $order): bool
    {
        return trim((string) $order->temp_reference) !== ''
            || trim((string) $order->receipt_number) !== '';
    }

    private function isFallbackHeldShape(Order $order): bool
    {
        return $order->status === Order::STATUS_HELD
            && $this->hasCounterReference($order);
    }

    /** @param array{lat: float, lng: float}|null $gps */
    private function enforceClaimGeofence(Device $device, ?array $gps): void
    {
        $branch = Branch::query()
            ->whereKey((int) $device->branch_id)
            ->where('company_id', (int) $device->company_id)
            ->first();
        if ($branch === null || ! $this->geofence->isFenced($branch)) {
            return;
        }
        if ($gps === null) {
            throw new QrChargeException(
                'geofence_fix_required',
                409,
                'A GPS fix is required at this geofenced branch.',
            );
        }

        try {
            $this->geofence->assertWithin($branch, $gps['lat'], $gps['lng']);
        } catch (RuntimeException $exception) {
            throw new QrChargeException('geofence_outside', 409, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function present(Order $order, bool $alreadyClaimed): array
    {
        return [
            'order_uuid' => (string) $order->uuid,
            'status' => (string) $order->status,
            'receipt_number' => $order->receipt_number,
            'temp_reference' => $order->temp_reference,
            'charge_amount_baisas' => (int) $order->charge_amount_baisas,
            'charge_claimed_at' => $order->charge_claimed_at?->toIso8601String(),
            'charge_deadline_at' => $order->charge_deadline_at?->toIso8601String(),
            'already_claimed_by_this_device' => $alreadyClaimed,
        ];
    }
}
