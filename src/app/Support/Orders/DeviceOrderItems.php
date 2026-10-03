<?php

declare(strict_types=1);

namespace App\Support\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * An order's lines as a device resumes them (held orders, transfers).
 *
 * LAUNCH-P4 — combo children are not lines of their own: each top-level
 * combo line carries them back as `combo`, in the device wire shape per
 * ONE combo ({slot_id, product_id, product_name, qty, extra_price_baisas,
 * notes, addons}), so a resumed cart rebuilds the same combo and line
 * indexes (comps, discounts) keep pointing at the right lines.
 */
final class DeviceOrderItems
{
    /** @return Collection<int, OrderItem> the order's top-level lines, in id order */
    public static function lines(Order $order): Collection
    {
        return $order->items->filter(static fn (OrderItem $item): bool => $item->parent_order_item_id === null)->values();
    }

    /** @return list<array<string, mixed>> */
    public static function present(Order $order): array
    {
        $children = $order->items->filter(static fn (OrderItem $item): bool => $item->parent_order_item_id !== null)
            ->groupBy('parent_order_item_id');

        return self::lines($order)->map(static function (OrderItem $item) use ($children): array {
            $line = [
                'id' => (int) $item->id,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'product_name' => $item->product_name_snapshot,
                'qty' => (float) $item->qty,
                'unit_price_baisas' => Money::toBaisas($item->unit_price_snapshot),
                'line_discount_baisas' => Money::toBaisas($item->line_discount),
                'line_total_baisas' => Money::toBaisas($item->line_total),
                'status' => $item->status,
                'notes' => $item->notes,
                'addons' => self::addons($item),
            ];
            $combo = $children->get($item->id);
            if ($combo !== null) {
                $line['combo'] = $combo->map(static fn (OrderItem $child): array => [
                    'id' => (int) $child->id,
                    'slot_id' => $child->combo_slot_id !== null ? (int) $child->combo_slot_id : null,
                    'product_id' => $child->product_id !== null ? (int) $child->product_id : null,
                    'product_name' => $child->product_name_snapshot,
                    'qty' => (float) $item->qty > 0 ? round((float) $child->qty / (float) $item->qty, 3) : (float) $child->qty,
                    'extra_price_baisas' => Money::toBaisas($child->combo_extra_price ?? 0),
                    'notes' => $child->notes,
                    'addons' => self::addons($child),
                ])->values()->all();
            }

            return $line;
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private static function addons(OrderItem $item): array
    {
        return $item->addons->map(static fn (OrderItemAddon $addon): array => [
            'add_on_id' => $addon->add_on_id !== null ? (int) $addon->add_on_id : null,
            'add_on_name' => $addon->add_on_name_snapshot,
            'price_delta_baisas' => Money::toBaisas($addon->price_delta_snapshot),
        ])->values()->all();
    }
}
