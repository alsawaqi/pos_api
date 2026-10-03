<?php

declare(strict_types=1);

namespace App\Support\Orders;

use App\Models\OrderItem;

/**
 * LAUNCH-P4 — a combo line's children follow its quantity: a child's qty is
 * the combo qty × the choice qty, so when a server path reduces a combo
 * line (a table line cancellation, a quick-order edit) its children are
 * reduced in the same proportion, and void with it at zero.
 */
final class ComboChildren
{
    public static function follow(OrderItem $parent, float $oldQty, float $newQty): void
    {
        if ($oldQty <= 0) {
            return;
        }
        $children = OrderItem::query()->where('order_id', $parent->order_id)
            ->where('parent_order_item_id', $parent->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($children as $child) {
            $qty = round((float) $child->qty * $newQty / $oldQty, 3);
            $child->update(['qty' => $qty] + ($qty <= 0 ? ['status' => OrderItem::STATUS_VOID] : []));
        }
    }
}
