<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * LAUNCH combo add-on (owner decisions 3, 4, 7; fix order 1 tester call 4) —
 * the unit price of one order line, in baisas. The same rule is in
 * mithqal_pricing v0.4.0; the shared vectors are
 * tests/Fixtures/combo_pricing_vectors.json.
 *
 *   item (inside a combo or meal)  qty × max(0, extra_or_upgrade + Σ its add-ons)
 *                                  — an item never goes below 0
 *   standard line                  max(0, base + Σ add-ons)
 *   combo line                     max(0, combo price + Σ items)
 *   meal line                      max(0, max(0, main price + Σ main add-ons)
 *                                  + meal price + Σ items) — the main is an
 *                                  item too
 *
 * A Remove add-on may be below 0 ("No cheese −0.100"); the floors keep every
 * item and every line at 0 or more.
 */
final class ComboPricing
{
    /** @param list<int> $addonDeltas */
    public static function item(int $qty, int $extraBaisas, array $addonDeltas): int
    {
        return $qty * max(0, $extraBaisas + array_sum($addonDeltas));
    }

    /**
     * @param  list<int>  $baseAddonDeltas  the line product's own add-ons (a meal: the main's)
     * @param  list<int>  $itemPrices  each item's price from {@see item()}
     */
    public static function unit(string $kind, int $baseBaisas, array $baseAddonDeltas, int $mealPriceBaisas, array $itemPrices): int
    {
        return match ($kind) {
            'meal' => max(0, max(0, $baseBaisas + array_sum($baseAddonDeltas)) + $mealPriceBaisas + array_sum($itemPrices)),
            'combo' => max(0, $baseBaisas + array_sum($baseAddonDeltas) + array_sum($itemPrices)),
            default => max(0, $baseBaisas + array_sum($baseAddonDeltas)),
        };
    }
}
