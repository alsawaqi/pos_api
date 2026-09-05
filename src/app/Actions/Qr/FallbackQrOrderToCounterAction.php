<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Guarded payment-station transition from path A to the attended counter. */
final class FallbackQrOrderToCounterAction
{
    public function __construct(
        private readonly QrChargeRecoveryGuard $recoveryGuard,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /**
     * @return array{order_uuid: string, receipt_number: string|null, temp_reference: string|null, status: string}
     */
    public function handle(Device $device, string $orderUuid): array
    {
        return DB::transaction(function () use ($device, $orderUuid): array {
            $now = now();
            $order = Order::query()
                ->where('uuid', trim($orderUuid))
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }

            $this->expireTouchedDineInSession($order, $now);
            $order->refresh();

            if ($order->status === Order::STATUS_HELD) {
                $isAttendedRecovery = $this->recoveryGuard->isAmbiguousCharge($order, $now);
                if ($isAttendedRecovery) {
                    $this->assertAttendedDevice($device);
                    $this->lockSessionForAttendedRecovery($order, $device);
                } elseif ($this->hasNoChargeProvenance($order)
                    && $this->hasCounterReference($order)
                    && $this->stationOffContext($order, $device, $now)[0]) {
                    return $this->present($order);
                } else {
                    $this->lockBoundOrderedSession($order, $device);
                }
                if ($isAttendedRecovery
                    || $this->hasCounterReference($order)) {
                    return $this->present($order);
                }

                throw new QrChargeException(
                    'order_already_held',
                    409,
                    'The order is already held without a reference.',
                );
            }
            $wasOpen = $order->status === Order::STATUS_OPEN;
            [$isStationOffRouting, $routingSession] = $wasOpen
                ? $this->stationOffContext($order, $device, $now) : [false, null];
            if ($order->status !== Order::STATUS_AWAITING_PAYMENT && ! ($wasOpen && $isStationOffRouting)) {
                throw new QrChargeException(
                    'order_not_awaiting_payment',
                    409,
                    'The order is not awaiting payment.',
                );
            }

            $isAttendedRecovery = $this->recoveryGuard->isAmbiguousCharge($order, $now);
            $isExpiredDineInRecovery = false;
            $isDeletedSessionDineInRecovery = false;
            if ($isAttendedRecovery) {
                $this->assertAttendedDevice($device);
                $this->lockSessionForAttendedRecovery($order, $device);
            } elseif (! ($wasOpen ? $this->hasAffirmativelySafeCharge($order) : $this->isWithoutLiveClaim($order, $now))) {
                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'A live charge claim prevents fallback to the counter.',
                );
            } else {
                if (! $wasOpen) {
                    [$isStationOffRouting, $routingSession] = $this->stationOffContext($order, $device, $now);
                }
                // r2's orphan flow can lazily move an ordered dine-in session
                // to expired while its never-claimed awaiting-payment order
                // remains unpaid. A payment station can no longer prove the
                // ordered-session gate in that state, so only an attended
                // same-branch device may recover the affirmatively-safe order.
                // Ambiguous claims stay on the separate residue-preserving path.
                if ($isStationOffRouting) {
                    // D7 is attended routing only; no credential or charge
                    // ownership is transferred to the cashier here.
                } elseif ($this->isDeletedSessionDineInSafeRecovery($order, $device)) {
                    // Hard-deleting the opening station cascades its session
                    // and NULLs the surviving order FK. The table-scoped board
                    // remains the recovery root, but only an attended device
                    // may move this affirmatively never-charged order onward.
                    $isDeletedSessionDineInRecovery = true;
                } elseif ($this->lockExpiredDineInSessionForSafeRecovery($order, $device) !== null) {
                    $isExpiredDineInRecovery = true;
                } else {
                    $this->lockBoundOrderedSession($order, $device);
                }
            }

            $updates = ['status' => Order::STATUS_HELD];

            // An attended recovery deliberately retains every charge fact.
            // The cashier needs the possibly-charged provenance to survive
            // the handoff, and late station evidence still has to enter the
            // existing orphan/reconciliation path. Only an affirmatively safe
            // station fallback clears the old claim before counter settlement.
            if (! $isAttendedRecovery) {
                $updates += [
                    'charge_device_id' => null,
                    'charge_amount_baisas' => null,
                    'charge_claimed_at' => null,
                    'charge_deadline_at' => null,
                    'charge_outcome' => null,
                ];
                if ($isExpiredDineInRecovery
                    || $isDeletedSessionDineInRecovery
                    || $this->isDineInOrder($order)) {
                    // Dine-in never carries round-up. Clear the sixth shipped
                    // provenance column as well; quick's legacy zero remains
                    // byte-compatible on its existing fallback path.
                    $updates['charge_roundup_amount_baisas'] = null;
                }
            }

            $order->update($updates);
            if ($wasOpen) {
                // HELD admission in settlement/reopen requires ORDERED for a
                // surviving credential. Never revive an expired credential.
                if ($routingSession?->status === QrSession::STATUS_ACTIVE) {
                    $routingSession->update(['status' => QrSession::STATUS_ORDERED, 'last_seen_at' => $now]);
                }
                if ($order->table_session_id !== null) {
                    $seating = TableSession::query()->whereKey((int) $order->table_session_id)
                        ->where('company_id', (int) $order->company_id)->where('branch_id', (int) $order->branch_id)
                        ->where('table_id', (int) $order->table_id)->lockForUpdate()->first();
                    if ($seating === null) {
                        throw new QrChargeException('order_not_bound_to_device_session', 409, 'The seating does not match this order.');
                    }
                    if ($seating->status === TableSession::STATUS_OPEN) {
                        $seating->update(['status' => TableSession::STATUS_BILLING, 'billing_at' => $now]);
                        $this->journal->handle($seating, 'billing', ['order_uuid' => (string) $order->uuid], (int) $device->id, $now);
                    }
                }
            }
            $this->journal->forOrder($order, 'sent_to_counter', [], (int) $device->id);

            return $this->present($order->refresh());
        });
    }

    private function hasCounterReference(Order $order): bool
    {
        return trim((string) $order->temp_reference) !== ''
            || trim((string) $order->receipt_number) !== '';
    }

    /** @return array{bool, ?QrSession} */
    private function stationOffContext(Order $order, Device $device, CarbonInterface $at): array
    {
        if (! $this->isDineInOrder($order) || ! $this->recoveryGuard->isAttendedDevice($device)
            || ! $device->isAssigned() || $device->trashed() || $device->status !== 'active'
            || (int) $order->company_id !== (int) $device->company_id
            || (int) $order->branch_id !== (int) $device->branch_id) {
            return [false, null];
        }
        if ($order->qr_session_id === null) {
            return [true, null];
        }
        $session = QrSession::query()->whereKey((int) $order->qr_session_id)
            ->where('company_id', (int) $order->company_id)->where('branch_id', (int) $order->branch_id)
            ->where('table_id', (int) $order->table_id)->lockForUpdate()->first();
        if ($session === null) {
            throw new QrChargeException('order_not_bound_to_device_session', 409, 'The credential does not match this order.');
        }
        $station = $session->device_id === null ? null : Device::query()->withTrashed()
            ->whereKey((int) $session->device_id)->where('company_id', (int) $order->company_id)
            ->where('branch_id', (int) $order->branch_id)->first();

        return [$session->status === QrSession::STATUS_EXPIRED || $session->isExpiredAt($at)
            || $station === null || $station->trashed() || $station->status !== 'active'
            || ! $station->isAssigned() || ! $station->isPaymentStation(), $session];
    }

    private function hasAffirmativelySafeCharge(Order $order): bool
    {
        return ($order->charge_claimed_at === null && $order->charge_outcome === null)
            || in_array($order->charge_outcome, [Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED], true);
    }

    private function hasNoChargeProvenance(Order $order): bool
    {
        foreach (['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas',
            'charge_claimed_at', 'charge_deadline_at', 'charge_outcome'] as $field) {
            if ($order->getRawOriginal($field) !== null) {
                return false;
            }
        }

        return true;
    }

    private function isWithoutLiveClaim(Order $order, CarbonInterface $at): bool
    {
        return Order::query()
            ->whereKey($order->getKey())
            ->withoutLiveClaim($at)
            ->exists();
    }

    /**
     * Staff fallback is a lazy-expiry boundary for the exact dine-in session
     * it touches. The update is conditional and one-way, matching
     * ResolveQrSession; a successful recovery commits it with the fallback.
     */
    private function expireTouchedDineInSession(Order $order, CarbonInterface $at): void
    {
        if (! $this->isDineInOrder($order) || $order->qr_session_id === null) {
            return;
        }

        QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->where('company_id', (int) $order->company_id)
            ->where('branch_id', (int) $order->branch_id)
            ->where('table_id', (int) $order->table_id)
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->where('expires_at', '<=', $at)
            ->update([
                'status' => QrSession::STATUS_EXPIRED,
                'closed_at' => $at,
                'updated_at' => $at,
            ]);
    }

    private function assertAttendedDevice(Device $device): void
    {
        if (! $this->recoveryGuard->isAttendedDevice($device)) {
            throw new QrChargeException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may recover an ambiguous charge.',
            );
        }
    }

    private function lockSessionForAttendedRecovery(Order $order, Device $device): ?QrSession
    {
        if ($order->qr_session_id === null) {
            if ($this->isDineInOrder($order)
                && (int) $order->company_id === (int) $device->company_id
                && (int) $order->branch_id === (int) $device->branch_id) {
                return null;
            }

            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a station session.',
            );
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->where('company_id', (int) $order->company_id)
            ->where('branch_id', (int) $order->branch_id)
            ->lockForUpdate()
            ->first();

        if ($session === null
            || (int) $order->company_id !== (int) $device->company_id
            || (int) $order->branch_id !== (int) $device->branch_id
            || (int) $session->company_id !== (int) $device->company_id
            || (int) $session->branch_id !== (int) $device->branch_id) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a station session in this till branch.',
            );
        }

        return $session;
    }

    private function lockExpiredDineInSessionForSafeRecovery(
        Order $order,
        Device $device,
    ): ?QrSession {
        if (! $this->recoveryGuard->isAttendedDevice($device)
            || ! $this->isDineInOrder($order)
            || $order->qr_session_id === null) {
            return null;
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->whereNotNull('table_id')
            ->lockForUpdate()
            ->first();

        return $session?->status === QrSession::STATUS_EXPIRED
            ? $session
            : null;
    }

    private function isDeletedSessionDineInSafeRecovery(Order $order, Device $device): bool
    {
        return $order->qr_session_id === null
            && $this->recoveryGuard->isAttendedDevice($device)
            && $this->isDineInOrder($order)
            && (int) $order->company_id === (int) $device->company_id
            && (int) $order->branch_id === (int) $device->branch_id;
    }

    private function isDineInOrder(Order $order): bool
    {
        return $order->source === Order::SOURCE_QR_WEB
            && $order->order_type === 'dine_in'
            && $order->table_id !== null;
    }

    private function lockBoundOrderedSession(Order $order, Device $device): QrSession
    {
        if (! $device->isPaymentStation() || $order->qr_session_id === null) {
            throw new QrChargeException(
                $device->isPaymentStation()
                    ? 'order_not_bound_to_device_session'
                    : 'device_not_payment_station',
                409,
                $device->isPaymentStation()
                    ? 'The order is not bound to this station session.'
                    : 'Only a payment station may move this order to the counter.',
            );
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->lockForUpdate()
            ->first();

        if ($session === null
            || (int) $session->company_id !== (int) $device->company_id
            || (int) $session->branch_id !== (int) $device->branch_id
            || (! $session->isDineIn()
                && (int) $session->device_id !== (int) $device->getKey())) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to this station session.',
            );
        }

        if ($session->status !== QrSession::STATUS_ORDERED) {
            throw new QrChargeException(
                'session_not_ordered',
                409,
                'The QR session is not in the ordered state.',
            );
        }

        return $session;
    }

    /**
     * @return array{order_uuid: string, receipt_number: string|null, temp_reference: string|null, status: string}
     */
    private function present(Order $order): array
    {
        return [
            'order_uuid' => (string) $order->uuid,
            'receipt_number' => is_string($order->receipt_number)
                && trim($order->receipt_number) !== ''
                    ? (string) $order->receipt_number
                    : null,
            'temp_reference' => $order->temp_reference,
            'status' => (string) $order->status,
        ];
    }
}
