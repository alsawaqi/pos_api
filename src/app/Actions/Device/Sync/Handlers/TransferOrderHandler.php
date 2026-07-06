<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use RuntimeException;

/**
 * Device-to-device order transfer (send leg).
 *
 * Processes an `order.transfer` sync event: the payload is exactly
 * order.hold's `order` object PLUS a `target_device_id`. The order is upserted
 * as a held mirror via {@see CreateOrderHandler::writeOrder} (same items /
 * addons / discounts / comps machinery, upsert-by-uuid, no geofence — a hold
 * moves no money or stock), then stamped as addressed to the target device.
 *
 * The target device sees it in GET /device/transfers/incoming and CLAIMS it
 * (DeviceTransfersController::claim), which reassigns ownership and clears the
 * target. order.pay finalises it against the same uuid once the target
 * completes payment.
 *
 * The target MUST be a different assigned device in the SAME company + branch
 * (you can only transfer to a colleague's terminal at your own branch). The
 * dispatcher's branch broadcast then nudges the target's live listener; the
 * handheld (no socket) picks it up on its next inbox poll.
 */
class TransferOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly CreateOrderHandler $create,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) ($event->payload_json ?? []);
        $targetId = isset($payload['target_device_id']) ? (int) $payload['target_device_id'] : 0;
        if ($targetId <= 0) {
            throw new RuntimeException('order.transfer requires a target_device_id');
        }
        if ($targetId === (int) $device->getKey()) {
            throw new RuntimeException('cannot transfer an order to the sending device');
        }

        // The target must be a real, assigned, in-service device at the SAME
        // branch — never another branch/company, never a blocked/retired one.
        $target = Device::query()
            ->whereKey($targetId)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->whereNotIn('status', ['blocked', 'inactive'])
            ->first();
        if ($target === null) {
            throw new RuntimeException('target device is not an active device at this branch');
        }

        // Upsert the order as a held mirror (same path as order.hold), then
        // address it to the target. device_id stays the SENDER until the
        // target claims it, so a cancelled/expired transfer still belongs to
        // whoever created it.
        $result = $this->create->writeOrder($event, $device, Order::STATUS_HELD, enforceGeofence: false);

        $order = Order::query()->whereKey($result['order_id'])->firstOrFail();
        $order->update([
            'transferred_to_device_id' => $target->getKey(),
            'transferred_from_device_id' => $device->getKey(),
            'transferred_at' => now(),
        ]);

        return $result + [
            'transferred_to_device_id' => (int) $target->getKey(),
            'target_device_name' => $target->name,
        ];
    }
}
