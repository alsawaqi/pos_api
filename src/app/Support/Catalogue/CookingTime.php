<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on (owner decision D11, tester call 14) — cooking time.
 *
 *  - pos_products.cooking_minutes: 0..240, NULL = not set (nothing shown).
 *  - The menu figure of a combo: its own value, else the longest of its
 *    options.
 *  - pos_order_items.cooking_minutes: a server-side snapshot taken when a
 *    line is written (devices send nothing new); a combo parent stores its
 *    longest child, or its own value when no child has one.
 *  - "Ready in about N min" = the longest live item of the order (or of a
 *    round); NULL when no item has a value.
 */
final class CookingTime
{
    public const MAX = 240;

    public static function of(?object $product): ?int
    {
        $value = $product?->cooking_minutes ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, min(self::MAX, (int) $value));
    }

    /** @param iterable<int|string|null> $values */
    public static function longest(iterable $values): ?int
    {
        $longest = null;
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $longest = max($longest ?? 0, (int) $value);
        }

        return $longest;
    }

    /**
     * The menu figure of a combo (tester call 14), the same on devices and the
     * QR menu: its own value, else the longest of its options on sale.
     *
     * @param  iterable<int|string|null>  $optionMinutes
     */
    public static function comboFigure(?object $combo, iterable $optionMinutes): ?int
    {
        return self::of($combo) ?? self::longest($optionMinutes);
    }

    /**
     * "Ready in about N min" for an order: the longest cooking-time snapshot
     * of its live (not void) lines, combo parents and children alike; null
     * when no line has one.
     */
    public static function readyInForOrder(int $orderId): ?int
    {
        $value = DB::table('pos_order_items')
            ->where('order_id', $orderId)
            ->where('status', '<>', OrderItem::STATUS_VOID)
            ->max('cooking_minutes');

        return $value === null ? null : (int) $value;
    }

    /**
     * "Ready in about N min" for an accepted dine-in round: the longest
     * cooking-time snapshot of the order lines the round wrote (its frozen
     * lines' order_item_id) and their combo children, leaving out cancelled
     * (void) lines. The frozen lines themselves keep their shape. Null for a
     * round not accepted yet, or written before the add-on.
     *
     * @param  list<array<string, mixed>>|null  $pricedLines
     */
    public static function readyInForRound(?array $pricedLines): ?int
    {
        $itemIds = [];
        foreach ($pricedLines ?? [] as $line) {
            if (is_array($line) && isset($line['order_item_id']) && is_numeric($line['order_item_id'])) {
                $itemIds[] = (int) $line['order_item_id'];
            }
        }
        if ($itemIds === []) {
            return null;
        }
        $value = DB::table('pos_order_items')
            ->where(fn ($q) => $q->whereIn('id', $itemIds)->orWhereIn('parent_order_item_id', $itemIds))
            ->where('status', '<>', OrderItem::STATUS_VOID)
            ->max('cooking_minutes');

        return $value === null ? null : (int) $value;
    }

    /**
     * The snapshot of a line: a combo parent's longest child (else its own
     * value); any other line its product's value.
     *
     * @param  list<int|null>  $childMinutes
     */
    public static function forLine(?Product $product, array $childMinutes = []): ?int
    {
        if ($product !== null && $product->isCombo()) {
            return self::longest($childMinutes) ?? self::of($product);
        }

        return self::of($product);
    }
}
