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
     * @return array{order_uuid: string, receipt_number: string, status: string}
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
                $this->lockBoundOrderedSession($order, $device);
                if (is_string($order->receipt_number) && trim($order->receipt_number) !== '') {
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
            if (! $this->isWithoutLiveClaim($order, $now)) {
                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'A live charge claim prevents fallback to the counter.',
                );
            }

            $session = $this->lockBoundOrderedSession($order, $device);
            if ($session->status !== QrSession::STATUS_ORDERED) {
                throw new QrChargeException(
                    'session_not_ordered',
                    409,
                    'The QR session is not in the ordered state.',
                );
            }

            $allocation = $this->allocateOrderNumber->handle($device);
            if ($allocation === null) {
                throw new QrChargeException(
                    'numbering_disabled',
                    409,
                    'Order numbering is not enabled for this company.',
                );
            }

            $order->update([
                'receipt_number' => $allocation['formatted'],
                'status' => Order::STATUS_HELD,
                'charge_device_id' => null,
                'charge_amount_baisas' => null,
                'charge_claimed_at' => null,
                'charge_deadline_at' => null,
                'charge_outcome' => null,
            ]);

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
     * @return array{order_uuid: string, receipt_number: string, status: string}
     */
    private function present(Order $order): array
    {
        return [
            'order_uuid' => (string) $order->uuid,
            'receipt_number' => (string) $order->receipt_number,
            'status' => (string) $order->status,
        ];
    }
}
