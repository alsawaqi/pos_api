<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Device-to-device order transfer — the target's side.
 *
 *   GET  /api/v1/device/transfers/incoming        orders addressed to me
 *   POST /api/v1/device/transfers/{uuid}/claim    take one into my cart
 *
 * The SEND leg is the `order.transfer` sync event ({@see TransferOrderHandler});
 * these two reads/writes let the receiving device list what's waiting for it
 * and atomically claim one (so two devices can never both grab the same
 * order). Online-only — a transfer targets a live colleague's terminal, and
 * the claim reassigns ownership under a row lock.
 */
class DeviceTransfersController
{
    /** Only unpaid orders can still be transferred/claimed. */
    private const CLAIMABLE = [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_KITCHEN];

    public function incoming(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $orders = Order::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->where('transferred_to_device_id', $device->getKey())
            ->whereIn('status', self::CLAIMABLE)
            ->with('items.addons')
            ->orderBy('transferred_at')
            ->get();

        // Resolve sender names in one query for the "from Main POS" label.
        $fromIds = $orders->pluck('transferred_from_device_id')->filter()->unique()->all();
        $names = Device::query()->whereKey($fromIds)->pluck('name', 'id');

        return response()->json([
            'data' => [
                'transfers' => $orders->map(fn (Order $o): array => $this->mapOrder($o, $names))->all(),
            ],
            'meta' => ['money_unit' => 'baisas', 'count' => $orders->count()],
            'errors' => [],
        ]);
    }

    public function claim(Request $request, string $uuid): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $result = DB::transaction(function () use ($device, $uuid): array|JsonResponse {
            $order = Order::query()->where('uuid', $uuid)->lockForUpdate()->first();

            // Not found / not mine / already claimed / already terminal all
            // collapse to one honest "no longer available" (never leak another
            // tenant's order, and let a double-tap fail gracefully).
            if ($order === null
                || (int) $order->company_id !== (int) $device->company_id
                || (int) $order->branch_id !== (int) $device->branch_id
                || (int) ($order->transferred_to_device_id ?? 0) !== (int) $device->getKey()
                || ! in_array($order->status, self::CLAIMABLE, true)) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'transfer_unavailable', 'message' => 'This transfer is no longer available.']],
                ], 409);
            }

            // Take ownership; clear the target so it leaves every inbox. Keep
            // transferred_from_device_id for display ("received from …").
            $order->update([
                'device_id' => $device->getKey(),
                'transferred_to_device_id' => null,
                'transferred_at' => null,
            ]);

            return ['order' => $order->fresh(['items.addons'])];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        /** @var Order $order */
        $order = $result['order'];
        $names = Device::query()->whereKey([$order->transferred_from_device_id])->pluck('name', 'id');

        return response()->json([
            'data' => ['order' => $this->mapOrder($order, $names)],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }

    /**
     * Full order snapshot (matches DeviceOrdersController's active-order shape)
     * PLUS the transfer metadata the receiving UI needs — so the target can
     * rebuild the exact cart and show who sent it.
     *
     * @param  \Illuminate\Support\Collection<int, string|null>  $names
     * @return array<string, mixed>
     */
    private function mapOrder(Order $order, $names): array
    {
        $fromId = $order->transferred_from_device_id !== null ? (int) $order->transferred_from_device_id : null;

        return [
            'id' => (int) $order->id,
            'uuid' => $order->uuid,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'source' => $order->source,
            'table_id' => $order->table_id !== null ? (int) $order->table_id : null,
            'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
            'staff_id' => $order->staff_id !== null ? (int) $order->staff_id : null,
            'plate_number' => $order->plate_number,
            'receipt_number' => $order->receipt_number,
            'transferred_from_device_id' => $fromId,
            'transferred_from_name' => $fromId !== null ? ($names[$fromId] ?? ('Device #'.$fromId)) : null,
            'transferred_at' => $order->transferred_at?->toIso8601String(),
            'opened_at' => $order->opened_at?->toIso8601String(),
            'subtotal_baisas' => Money::toBaisas($order->subtotal),
            'discount_total_baisas' => Money::toBaisas($order->discount_total),
            'tax_total_baisas' => Money::toBaisas($order->tax_total),
            'grand_total_baisas' => Money::toBaisas($order->grand_total),
            'note' => $order->note,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => (int) $item->id,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'product_name' => $item->product_name_snapshot,
                'qty' => (float) $item->qty,
                'unit_price_baisas' => Money::toBaisas($item->unit_price_snapshot),
                'line_discount_baisas' => Money::toBaisas($item->line_discount),
                'line_total_baisas' => Money::toBaisas($item->line_total),
                'status' => $item->status,
                'notes' => $item->notes,
                'addons' => $item->addons->map(fn (OrderItemAddon $addon): array => [
                    'add_on_id' => $addon->add_on_id !== null ? (int) $addon->add_on_id : null,
                    'add_on_name' => $addon->add_on_name_snapshot,
                    'price_delta_baisas' => Money::toBaisas($addon->price_delta_snapshot),
                ])->all(),
            ])->all(),
        ];
    }
}
