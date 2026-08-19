<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class LineTotals
{
    /** @param list<PricingLine> $lines */
    public static function rawSubtotalBaisas(array $lines): int
    {
        return array_reduce($lines, static fn (int $sum, PricingLine $line): int => $sum + $line->lineTotalBaisas(), 0);
    }

    public static function lineNetBaisas(PricingLine $line, int $lineDiscountBaisas): int
    {
        return max(0, $line->lineTotalBaisas() - $lineDiscountBaisas);
    }
}
