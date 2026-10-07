<?php

declare(strict_types=1);

namespace App\Support\Orders;

use App\Models\OrderItem;
use App\Support\Money;
use App\Support\Pricing\ComboAllocation;

/**
 * LAUNCH-P4 — a combo line's children follow its quantity: a child's qty is
 * the combo qty × the choice qty, so when a server path reduces a combo
 * line (a table line cancellation, a quick-order edit) its children are
 * reduced in the same proportion, and void with it at zero.
 */
final class ComboChildren
{
    /**
     * Fix order 1 (C-2) — the children's revenue shares follow too: they are
     * re-split to the parent's new paid total (line total − line discount,
     * as the caller has just written them), by their old shares, the last
     * child taking the remainder. Lines written before the add-on carry no
     * shares and keep none.
     */
    public static function follow(OrderItem $parent, float $oldQty, float $newQty): void
    {
        if ($oldQty <= 0) {
            return;
        }
        $children = OrderItem::query()->where('order_id', $parent->order_id)
            ->where('parent_order_item_id', $parent->id)->orderBy('id')->lockForUpdate()->get();
        $shares = null;
        if ($children->isNotEmpty() && $children->every(static fn (OrderItem $child): bool => $child->allocated_revenue_baisas !== null)) {
            $paid = max(0, Money::toBaisas($parent->line_total) - Money::toBaisas($parent->line_discount));
            $shares = ComboAllocation::split($newQty <= 0 ? 0 : $paid,
                $children->map(static fn (OrderItem $child): int => (int) $child->allocated_revenue_baisas)->values()->all());
        }
        foreach ($children->values() as $index => $child) {
            $qty = round((float) $child->qty * $newQty / $oldQty, 3);
            $child->update(['qty' => $qty]
                + ($qty <= 0 ? ['status' => OrderItem::STATUS_VOID] : [])
                + ($shares !== null ? ['allocated_revenue_baisas' => $shares[$index]] : []));
        }
    }
}
