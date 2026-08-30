<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\AllocateOrderNumberAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Guarded payment-station transition from path A to the attended counter. */
final class FallbackQrOrderToCounterAction
{
    public function __construct(
        private readonly AllocateOrderNumberAction $allocateOrderNumber,
        private readonly QrChargeRecoveryGuard $recoveryGuard,
    ) {}

    /**
     * @return array{order_uuid: string, receipt_number: string|null, status: string}
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
                } else {
                    $this->lockBoundOrderedSession($order, $device);
                }
                if ($isAttendedRecovery
                    || (is_string($order->receipt_number) && trim($order->receipt_number) !== '')) {
                    return $this->present($order);
                }

                throw new QrChargeException(
                    'order_already_held',
                    409,
                    'The order is already held without an allocated receipt number.',
                );
            }
            if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
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
            } elseif (! $this->isWithoutLiveClaim($order, $now)) {
                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'A live charge claim prevents fallback to the counter.',
                );
            } else {
                // r2's orphan flow can lazily move an ordered dine-in session
                // to expired while its never-claimed awaiting-payment order
                // remains unpaid. A payment station can no longer prove the
                // ordered-session gate in that state, so only an attended
                // same-branch device may recover the affirmatively-safe order.
                // Ambiguous claims stay on the separate residue-preserving path.
                if ($this->isDeletedSessionDineInSafeRecovery($order, $device)) {
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

            $receiptNumber = is_string($order->receipt_number)
                && trim($order->receipt_number) !== ''
                    ? (string) $order->receipt_number
                    : null;
            $allocation = $receiptNumber === null
                ? $this->allocateOrderNumber->handle($device)
                : null;
            if ($receiptNumber === null && $allocation === null && ! $isAttendedRecovery) {
                throw new QrChargeException(
                    'numbering_disabled',
                    409,
                    'Order numbering is not enabled for this company.',
                );
            }

            $updates = [
                'receipt_number' => $receiptNumber ?? $allocation['formatted'] ?? null,
                'status' => Order::STATUS_HELD,
            ];

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

            return $this->present($order->refresh());
        });
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
     * @return array{order_uuid: string, receipt_number: string|null, status: string}
     */
    private function present(Order $order): array
    {
        return [
            'order_uuid' => (string) $order->uuid,
            'receipt_number' => is_string($order->receipt_number)
                && trim($order->receipt_number) !== ''
                    ? (string) $order->receipt_number
                    : null,
            'status' => (string) $order->status,
        ];
    }
}
