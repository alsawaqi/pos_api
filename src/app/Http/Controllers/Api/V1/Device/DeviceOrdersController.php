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
use Illuminate\Support\Carbon;

/**
 * Device order reads — blueprint §11.4.
 *
 *   GET /api/v1/device/orders/active   the branch's not-yet-terminal orders
 *                                       (open / held / kitchen) — resume / pay.
 *   GET /api/v1/device/orders/history   the branch's terminal orders (paid /
 *                                       void / refunded), paginated +
 *                                       date-filterable — so any device at the
 *                                       branch sees prior completed sales rung
 *                                       on ANY device (not just its own local
 *                                       store).
 *
 * Both are cross-device (scoped to the device's company + branch, online only
 * — §9.1.3) with money as integer baisas.
 */
class DeviceOrdersController
{
    /** Statuses considered "active" (not paid/void/refunded). */
    private const ACTIVE = [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_KITCHEN];

    /** Terminal statuses — the completed-sale history. */
    private const HISTORY = [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED];

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
     * GET /api/v1/device/orders/history — the branch's terminal (paid / void /
     * refunded) orders, most-recent first. Paginated (per_page default 50, max
     * 100) and optionally date-filtered on opened_at (?from=&to= ISO-8601).
     */
    public function history(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        $perPage = min(max((int) $request->query('per_page', '50'), 1), 100);
        $page = max((int) $request->query('page', '1'), 1);

        // Optional date window on opened_at. Parse defensively so a malformed
        // ?from/?to never 500s — an unparseable value is simply ignored.
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $paginator = Order::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->whereIn('status', self::HISTORY)
            ->when($from !== null, fn ($q) => $q->where('opened_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('opened_at', '<=', $to))
            ->with('items.addons')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        return response()->json([
            'data' => ['orders' => collect($paginator->items())->map(fn (Order $o): array => $this->mapOrder($o))->all()],
            'meta' => [
                'money_unit' => 'baisas',
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'errors' => [],
        ]);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
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
