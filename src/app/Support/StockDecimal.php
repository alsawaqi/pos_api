<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * LAUNCH-P2 P2-1 — the ledger's number formats.
 *
 * Ingredient quantities are stored to 4 decimals and per-base-unit costs to
 * 6 (numeric(14,4) / numeric(15,6)); money totals stay at 3. Values are
 * written as "at least 3 decimals, at most the column's scale", so every
 * value that fits 3 decimals reads exactly as before ("6.000", "0.350") and
 * only genuinely finer values show more ("0.0003", "0.00035").
 */
final class StockDecimal
{
    public const QUANTITY_SCALE = 4;

    public const UNIT_COST_SCALE = 6;

    public static function quantity(float|int|string|null $value): ?string
    {
        return self::format($value, 3, self::QUANTITY_SCALE);
    }

    public static function unitCost(float|int|string|null $value): ?string
    {
        return self::format($value, 3, self::UNIT_COST_SCALE);
    }

    /** Half-up rounding to $max decimals, trailing zeros trimmed down to $min. */
    public static function format(float|int|string|null $value, int $min, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $fixed = is_string($value)
            ? (string) BigDecimal::of(trim($value))->toScale($max, RoundingMode::HALF_UP)
            : number_format((float) $value, $max, '.', '');

        if (str_contains($fixed, '.')) {
            [$integer, $decimals] = explode('.', $fixed, 2);
            $decimals = str_pad(rtrim($decimals, '0'), $min, '0');
            $fixed = $decimals === '' ? $integer : $integer.'.'.$decimals;
        }

        // Never emit a signed zero ("-0.000").
        return preg_match('/^-0(\.0*)?$/', $fixed) === 1 ? substr($fixed, 1) : $fixed;
    }
}
