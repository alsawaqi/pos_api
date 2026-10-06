<?php

declare(strict_types=1);

namespace App\Support\Recipes;

/**
 * LAUNCH packaging add-on — "Used for" ticks (owner decisions 1–2, tester
 * calls 1–2).
 *
 * A stock line (recipe ingredient, physical item, add-on stock line, the
 * legacy add-on ingredient) carries `order_types`, a bit mask of the order
 * types it is taken for: 1 dine in, 2 quick order, 4 to go, 8 delivery;
 * 15 = all four (the default). Every copy carries the mask ONLY when it is
 * not 15, so a copy of an untagged line stays byte-identical to the copies
 * written before this release, and a copy without the key means "all".
 *
 * Copies are tagged when written and filtered when stock is TAKEN, by the
 * order's final type ({@see bucket()}), stamped on the order
 * (pos_orders.stock_order_type) so a void restores exactly what was taken.
 */
final class OrderTypes
{
    public const DINE_IN = 1;

    public const QUICK = 2;

    public const TO_GO = 4;

    public const DELIVERY = 8;

    public const ALL = 15;

    public const KEY = 'order_types';

    /** The four stock buckets (pos_orders.stock_order_type, pos_order_packaging_lines.order_type) and their bits. */
    public const BITS = ['dine_in' => self::DINE_IN, 'quick' => self::QUICK, 'to_go' => self::TO_GO, 'delivery' => self::DELIVERY];

    /**
     * The stock bucket of an order type: QR table and staff rounds are
     * already 'dine_in' and QR quick orders 'quick'; `car` (curb-side, no
     * current writer) is packed like 'to_go'. null for an unknown type.
     */
    public static function bucket(?string $orderType): ?string
    {
        return match ($orderType) {
            'dine_in', 'quick', 'to_go', 'delivery' => $orderType,
            'car' => 'to_go',
            default => null,
        };
    }

    /** The bit of a bucket; null = no filter (unknown, or stock taken before this release). */
    public static function bit(?string $bucket): ?int
    {
        return $bucket === null ? null : (self::BITS[$bucket] ?? null);
    }

    /** A stored or copied mask; anything absent or outside 1..15 reads as 15 (all). */
    public static function normalize(mixed $mask): int
    {
        if (is_int($mask) || (is_string($mask) && ctype_digit($mask)) || (is_float($mask) && floor($mask) === $mask)) {
            $mask = (int) $mask;

            return $mask >= 1 && $mask <= self::ALL ? $mask : self::ALL;
        }

        return self::ALL;
    }

    /** The mask of a copied line (absent = 15). @param array<string, mixed> $line */
    public static function of(array $line): int
    {
        return self::normalize($line[self::KEY] ?? null);
    }

    /** Whether a copied line is taken for this bit (null = every line is). @param array<string, mixed> $line */
    public static function applies(array $line, ?int $bit): bool
    {
        return $bit === null || (self::of($line) & $bit) !== 0;
    }

    /**
     * Fix order PK-A1 (M1) — ONE recipe line per item for kitchen production.
     *
     * A cooked product's recipe is made in batches, before any order type
     * exists, so its "Used for" ticks mean nothing there — and an item on
     * two lines with disjoint ticks (sugar 5 g dine in, 10 g to go) must
     * never be SUMMED into a batch (15 g, an amount no order uses). Rule:
     * per item keep the line ticked for the WIDEST set of order types (most
     * bits); on a tie the line that includes dine in; then the first line in
     * recipe order. Lines that are the only one of their item (every line
     * today: the old unique) pass unchanged, in their original order.
     *
     * @template T of array|object
     *
     * @param  iterable<T>  $lines  rows or arrays carrying $itemKey and order_types
     * @return list<T>
     */
    public static function widestLinePerItem(iterable $lines, string $itemKey = 'ingredient_id'): array
    {
        $lines = is_array($lines) ? array_values($lines) : iterator_to_array($lines, false);
        $keep = [];
        foreach ($lines as $index => $line) {
            $item = (int) data_get($line, $itemKey);
            $mask = self::normalize(data_get($line, self::KEY));
            $rank = [substr_count(decbin($mask), '1'), $mask & self::DINE_IN];
            if (! isset($keep[$item]) || $rank > $keep[$item]['rank']) {
                $keep[$item] = ['index' => $index, 'rank' => $rank];
            }
        }
        $indexes = array_flip(array_column($keep, 'index'));

        return array_values(array_filter($lines, static fn (int $index): bool => isset($indexes[$index]), ARRAY_FILTER_USE_KEY));
    }

    /**
     * The copy of a line with its mask: the key is added only when the mask
     * is not 15 (omit-when-15 keeps untagged copies byte-identical).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function tag(array $line, mixed $mask): array
    {
        $mask = self::normalize($mask);

        return $mask === self::ALL ? $line : $line + [self::KEY => $mask];
    }
}
