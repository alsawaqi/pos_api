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

            if ($order->status === Order::STATUS_HELD) {
                $isAttendedRecovery = $this->isAmbiguousCharge($order, $now);
                if ($isAttendedRecovery) {
                    $this->assertAttendedDevice($device);
                    $this->lockOrderedSessionForAttendedRecovery($order, $device);
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

            $isAttendedRecovery = $this->isAmbiguousCharge($order, $now);
            if ($isAttendedRecovery) {
                $this->assertAttendedDevice($device);
                $session = $this->lockOrderedSessionForAttendedRecovery($order, $device);
            } elseif (! $this->isWithoutLiveClaim($order, $now)) {
                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'A live charge claim prevents fallback to the counter.',
                );
            } else {
                $session = $this->lockBoundOrderedSession($order, $device);
            }

            if ($session->status !== QrSession::STATUS_ORDERED) {
                throw new QrChargeException(
                    'session_not_ordered',
                    409,
                    'The QR session is not in the ordered state.',
                );
            }

            $allocation = $this->allocateOrderNumber->handle($device);
            if ($allocation === null && ! $isAttendedRecovery) {
                throw new QrChargeException(
                    'numbering_disabled',
                    409,
                    'Order numbering is not enabled for this company.',
                );
            }

            $updates = [
                'receipt_number' => $allocation['formatted'] ?? $order->receipt_number,
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
     * A started claim whose result cannot safely be inferred by time alone.
     * Expired NULL covers the deadline-to-sweeper grace window; lapsed is the
     * server's durable post-sweep representation of the same uncertainty.
     */
    private function isAmbiguousCharge(Order $order, CarbonInterface $at): bool
    {
        if (in_array($order->charge_outcome, [
            Order::CHARGE_OUTCOME_LAPSED,
            Order::CHARGE_OUTCOME_UNCERTAIN,
        ], true)) {
            return true;
        }

        return $order->charge_claimed_at !== null
            && $order->charge_outcome === null
            && $order->charge_deadline_at !== null
            && $order->charge_deadline_at->lessThanOrEqualTo($at);
    }

    private function assertAttendedDevice(Device $device): void
    {
        if ($device->isPaymentStation() || $device->device_type === 'customer_tablet') {
            throw new QrChargeException(
                'device_not_attended_till',
                409,
                'An attended till must recover an ambiguous charge.',
            );
        }
    }

    private function lockOrderedSessionForAttendedRecovery(Order $order, Device $device): QrSession
    {
        if ($order->qr_session_id === null) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a station session.',
            );
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->lockForUpdate()
            ->first();

        if ($session === null
            || (int) $session->company_id !== (int) $device->company_id
            || (int) $session->branch_id !== (int) $device->branch_id) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to a station session in this till branch.',
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
            || (int) $session->device_id !== (int) $device->getKey()) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to this station session.',
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
