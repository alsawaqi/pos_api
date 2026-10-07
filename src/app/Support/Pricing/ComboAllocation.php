<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * LAUNCH combo add-on (owner decision 6, "profit split") — what each item of
 * a combo or meal earned: the line's money split across its items in
 * proportion to each item's NORMAL price (its own menu price), in baisas.
 * The shares add up exactly to what was paid.
 *
 * The split is per UNIT of ONE combo / meal (a "Burger × 2" child is two
 * units), in the children's order (a meal's main first, then the lines in
 * their order, the picks in the order sent):
 *
 *   share_k = round_half_up(price × weight_k / Σ weights)   for every unit
 *             but the last;
 *   last    = price − Σ the others (it takes the rounding remainder).
 *
 * A child's share is the sum of its units' shares; for a line of qty Q it is
 * that × Q (so the children add up to unit price × Q = the line total). All
 * weights 0 → equal weights. In the rare case the remainder would go below
 * 0 (tiny weights next to big ones), the cumulative split is used instead
 * (share_k = round(P·W_k/W) − round(P·W_{k−1}/W), never negative).
 *
 * Golden example (work order §3 Part A item 6): a Family box paid 5.000 with
 * 2 × Burger 2.000, Fries 1.000, Cola 1.000 → 1.667 + 1.667 + 0.833 + 0.833.
 * The same algorithm is in mithqal_pricing (Part B); the shared vectors are
 * in tests/Fixtures/combo_allocation_vectors.json.
 */
final class ComboAllocation
{
    /**
     * @param  list<int>  $weights  one per unit, >= 0
     * @return list<int>
     */
    public static function split(int $amount, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }
        $weights = array_map(static fn (int $weight): int => max(0, $weight), array_values($weights));
        $total = array_sum($weights);
        if ($total <= 0) {
            $weights = array_fill(0, $count, 1);
            $total = $count;
        }
        $shares = [];
        $given = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $shares[$i] = self::roundedShare($amount, $weights[$i], $total);
            $given += $shares[$i];
        }
        $shares[$count - 1] = $amount - $given;
        if ($amount >= 0 && $shares[$count - 1] < 0) {
            return self::cumulative($amount, $weights, $total);
        }

        return $shares;
    }

    /**
     * The children's shares of one combo / meal line.
     *
     * @param  list<array{weight: int, qty: int|float}>  $children  weight = the item's normal unit price in baisas, qty = per ONE combo
     * @return list<int>
     */
    public static function forLine(int $unitPriceBaisas, int|float $lineQty, int $lineTotalBaisas, array $children): array
    {
        if ($children === []) {
            return [];
        }
        $unitWeights = [];
        $owner = [];
        foreach (array_values($children) as $index => $child) {
            $qty = (float) $child['qty'];
            if ($qty >= 1 && $qty == floor($qty)) {
                for ($k = 0; $k < (int) $qty; $k++) {
                    $unitWeights[] = (int) $child['weight'];
                    $owner[] = $index;
                }
            } else {
                $unitWeights[] = (int) round($child['weight'] * $qty);
                $owner[] = $index;
            }
        }
        $perOne = array_fill(0, count($children), 0);
        foreach (self::split($unitPriceBaisas, $unitWeights) as $unit => $share) {
            $perOne[$owner[$unit]] += $share;
        }

        $qty = (float) $lineQty;
        if ($qty == floor($qty) && $unitPriceBaisas * (int) $qty === $lineTotalBaisas) {
            return array_map(static fn (int $share): int => $share * (int) $qty, $perOne);
        }

        return self::split($lineTotalBaisas, $perOne);
    }

    private static function roundedShare(int $amount, int $weight, int $total): int
    {
        $numerator = 2 * $amount * $weight;
        if ($numerator >= 0) {
            return intdiv($numerator + $total, 2 * $total);
        }

        return -intdiv(-$numerator + $total, 2 * $total);
    }

    /**
     * @param  list<int>  $weights
     * @return list<int>
     */
    private static function cumulative(int $amount, array $weights, int $total): array
    {
        $shares = [];
        $running = 0;
        $previous = 0;
        foreach ($weights as $i => $weight) {
            $running += $weight;
            $upTo = $i === count($weights) - 1 ? $amount : self::roundedShare($amount, $running, $total);
            $shares[] = $upTo - $previous;
            $previous = $upTo;
        }

        return $shares;
    }
}
