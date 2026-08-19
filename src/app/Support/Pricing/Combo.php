<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class Combo
{
    /**
     * @param  list<PricingLine>  $lines
     * @return array<string, list<int>>
     */
    public static function bundleInstances(OfferSpec $offer, array $lines): array
    {
        $instances = [];
        foreach ($lines as $index => $line) {
            if (str_starts_with($line->bundleKey, $offer->id.':')) {
                $instances[$line->bundleKey] ??= [];
                $instances[$line->bundleKey][] = $index;
            }
        }

        return $instances;
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  list<int>  $lineIndexes
     * @param  list<array<string, mixed>>  $groups
     */
    public static function bundleInstanceIntact(array $lines, array $lineIndexes, array $groups): bool
    {
        $remaining = $lineIndexes;
        foreach ($groups as $group) {
            $ids = self::intSetOf($group['product_ids'] ?? null);
            $requiredQty = min(max(self::intOf($group['qty'] ?? null, 1), 1), 99);
            foreach ($remaining as $position => $lineIndex) {
                if ($requiredQty === 0) {
                    break;
                }
                $productId = $lines[$lineIndex]->productId;
                if ($productId !== null && isset($ids[$productId])) {
                    $take = min(max($lines[$lineIndex]->qty, 0), $requiredQty);
                    $requiredQty -= $take;
                    unset($remaining[$position]);
                }
            }
            if ($requiredQty > 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, true> */
    public static function intSetOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $set = [];
        foreach ($value as $entry) {
            if (is_int($entry) || is_float($entry)) {
                $set[(int) $entry] = true;
            }
        }

        return $set;
    }

    public static function intOf(mixed $value, int $fallback = 0): int
    {
        return is_int($value) || is_float($value) ? (int) $value : $fallback;
    }

    public static function omrOf(mixed $value): float
    {
        return self::intOf($value) / 1000.0;
    }
}
