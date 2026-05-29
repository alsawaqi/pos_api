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

/**
 * GET /api/v1/device/orders/active — blueprint §11.4.
 *
 * The branch's active orders (open / held / in-kitchen — everything not yet
 * terminal) so a terminal can show, resume, or take payment on orders rung
 * on ANY device at the branch (cross-device visibility, online only — §9.1.3).
 * Scoped to the device's company + branch. Money is integer baisas.
 */
class DeviceOrdersController
{
    /** Statuses considered "active" (not paid/void/refunded). */
    private const ACTIVE = [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_KITCHEN];

    public function active(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        $orders = Order::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->whereIn('status', self::ACTIVE)
            ->with('items.addons')
            ->orderBy('opened_at')
            ->get();

        return response()->json([
            'data' => ['orders' => $orders->map(fn (Order $o): array => $this->mapOrder($o))->all()],
            'meta' => ['money_unit' => 'baisas', 'count' => $orders->count()],
            'errors' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrder(Order $order): array
    {
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
