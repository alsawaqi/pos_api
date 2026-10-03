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

    /** mithqal_pricing v0.3.0 `taxRateScale`: a rate in ten-thousandths of a percent (5% → 50000). */
    public const TAX_RATE_SCALE = 10000;

    /**
     * LAUNCH-P4 — inclusive taxes (mithqal_pricing v0.3.0): the taxed GROSS
     * base G already contains every tax. With R = Σ rᵢ, each row is
     * round(G × rᵢ / (100 + R)), half away from zero, rounded per row then
     * summed; the order's grand total is G itself.
     *
     * Computed EXACTLY in integers like the Dart core, never on a binary
     * double: sᵢ = round(rᵢ × 10000), S = Σ sᵢ, D = 100 × 10000 + S,
     * taxᵢ = (2 × G × sᵢ + D) div (2 × D) (the golden
     * inclusive_tax_half_baisa_midpoint_law pins an exact .5 quotient).
     *
     * @param  list<TaxSpec>  $taxes
     * @return list<TaxLineResult>
     */
    public static function inclusiveTaxLinesBaisasFor(int $grossBaseBaisas, array $taxes): array
    {
        $scaled = array_map(static fn (TaxSpec $tax): int => (int) round($tax->ratePercent * self::TAX_RATE_SCALE), $taxes);
        $denominator = 100 * self::TAX_RATE_SCALE + array_sum($scaled);

        return array_map(
            static fn (TaxSpec $tax, int $rate): TaxLineResult => new TaxLineResult(
                name: $tax->name,
                ratePercent: $tax->ratePercent,
                amountBaisas: $denominator <= 0 ? 0 : self::divRoundHalfAwayFromZero($grossBaseBaisas * $rate, $denominator),
                nameAr: $tax->nameAr,
            ),
            $taxes,
            $scaled,
        );
    }

    /** round($numerator / $denominator), halves away from zero, exact in integers ($denominator > 0). */
    private static function divRoundHalfAwayFromZero(int $numerator, int $denominator): int
    {
        $magnitude = intdiv(2 * abs($numerator) + $denominator, 2 * $denominator);

        return $numerator < 0 ? -$magnitude : $magnitude;
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
