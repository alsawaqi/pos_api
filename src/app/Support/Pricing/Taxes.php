<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class Taxes
{
    /**
     * @param  list<TaxSpec>  $taxes
     * @return list<TaxLineResult>
     */
    public static function taxLinesBaisasFor(int $taxedBaseBaisas, array $taxes): array
    {
        return array_map(
            static fn (TaxSpec $tax): TaxLineResult => new TaxLineResult(
                name: $tax->name,
                ratePercent: $tax->ratePercent,
                amountBaisas: (int) round($taxedBaseBaisas * $tax->ratePercent / 100),
                nameAr: $tax->nameAr,
            ),
            $taxes,
        );
    }

    /** @param list<TaxSpec> $taxes */
    public static function taxTotalBaisasFor(int $taxedBaseBaisas, array $taxes): int
    {
        return array_reduce(
            self::taxLinesBaisasFor($taxedBaseBaisas, $taxes),
            static fn (int $sum, TaxLineResult $line): int => $sum + $line->amountBaisas,
            0,
        );
    }
}
