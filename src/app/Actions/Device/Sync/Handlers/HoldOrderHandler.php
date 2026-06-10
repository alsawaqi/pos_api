<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;

/**
 * Phase C2 — processes an `order.hold` sync event (blueprint §6.7: "Hold:
 * persists current order to local store and to backend (so other devices can
 * resume it)").
 *
 * The payload is exactly order.create's `order` object; the row lands with
 * status=held via {@see CreateOrderHandler::writeOrder}'s upsert-by-uuid:
 * a re-hold of the same uuid replaces the mirror in place, the later finalize
 * order.create flips it to open, and order.void discards it (unpaid voids
 * carry no inventory unwind). Held mirrors surface on GET /device/orders/active.
 *
 * The geofence is deliberately NOT enforced here: a hold moves no money or
 * stock, and a fenced branch's offline-queued holds would otherwise become
 * permanently-failing outbox rows (no GPS fix at queue time). order.pay
 * re-checks the fence before any money moves.
 */
class HoldOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly CreateOrderHandler $create,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        return $this->create->writeOrder($event, $device, Order::STATUS_HELD, enforceGeofence: false);
    }
}
