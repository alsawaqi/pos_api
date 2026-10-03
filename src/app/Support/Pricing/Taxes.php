<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class Taxes
{
    /**
     * Exclusive taxes: each rate applied ON TOP of the taxed base, rounded
     * per row (round-then-sum).
     *
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
        return self::sum(self::taxLinesBaisasFor($taxedBaseBaisas, $taxes));
    }

    /**
     * LAUNCH-P4 — inclusive taxes (mithqal_pricing v0.3.0): the taxed GROSS
     * base G already contains every tax. With R = Σ rᵢ, each row is
     * round(G × rᵢ / (100 + R)), rounded per row then summed; the order's
     * grand total is G itself.
     *
     * @param  list<TaxSpec>  $taxes
     * @return list<TaxLineResult>
     */
    public static function inclusiveTaxLinesBaisasFor(int $grossBaseBaisas, array $taxes): array
    {
        $totalRate = 0.0;
        foreach ($taxes as $tax) {
            $totalRate += $tax->ratePercent;
        }

        return array_map(
            static fn (TaxSpec $tax): TaxLineResult => new TaxLineResult(
                name: $tax->name,
                ratePercent: $tax->ratePercent,
                amountBaisas: (int) round($grossBaseBaisas * $tax->ratePercent / (100 + $totalRate)),
                nameAr: $tax->nameAr,
            ),
            $taxes,
        );
    }

    /** @param list<TaxSpec> $taxes */
    public static function inclusiveTaxTotalBaisasFor(int $grossBaseBaisas, array $taxes): int
    {
        return self::sum(self::inclusiveTaxLinesBaisasFor($grossBaseBaisas, $taxes));
    }

    /** @param list<TaxLineResult> $lines */
    private static function sum(array $lines): int
    {
        return array_reduce(
            $lines,
            static fn (int $sum, TaxLineResult $line): int => $sum + $line->amountBaisas,
            0,
        );
    }
}
