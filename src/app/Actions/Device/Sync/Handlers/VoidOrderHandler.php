<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SyncEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8.3 — processes an `order.void` sync event: cancels the order and
 * its lines, and — if the order had already been paid — reverses its stock
 * consumption so the order nets to zero inventory impact.
 *
 * Payment refunds (negative payment rows / the `refunded` status) are NOT
 * handled here; that distinct flow lands in a later sub-phase. Void scoped
 * to the device's company + branch.
 */
class VoidOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;

        if (! is_string($orderUuid)) {
            throw new RuntimeException('invalid order.void payload: order_uuid required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for void: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID) {
            throw new RuntimeException('order already void: '.$orderUuid);
        }

        $voidedAt = isset($payload['voided_at']) ? Carbon::parse((string) $payload['voided_at']) : now();
        $wasPaid = $order->status === Order::STATUS_PAID;
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        return DB::transaction(function () use ($order, $voidedAt, $wasPaid, $reason): array {
            $order->update([
                'status' => Order::STATUS_VOID,
                'closed_at' => $voidedAt,
                'note' => $this->appendReason($order->note, $reason),
            ]);

            OrderItem::query()->where('order_id', $order->id)->update(['status' => OrderItem::STATUS_VOID]);

            $reversed = $wasPaid ? $this->inventory->reverse($order) : 0;

            return ['order_id' => (int) $order->id, 'status' => 'voided', 'reversed' => $reversed];
        });
    }

    private function appendReason(?string $note, ?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return $note;
        }
        $tag = 'VOID: '.$reason;

        return $note === null || $note === '' ? $tag : $note.' | '.$tag;
    }
}
