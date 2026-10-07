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
 * Fix order 1 — the amount split is what the line PAID: line total − line
 * discount (tester call 3); the weights are the items' prices for the order's
 * type (the delivery price on a delivery order; tester call 2). For a line of
 * whole quantity Q the units of all Q combos / meals are split at once (Q
 * copies of the unit weights, in order) and a child's share is the sum of its
 * units'. A fractional quantity splits the amount by the per-one shares of
 * the unit price. All weights 0 → equal weights. In the rare case the remainder would go below
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
        $qty = (float) $lineQty;
        if ($qty >= 1 && $qty == floor($qty)) {
            // Every unit of every combo / meal on the line, in order: the
            // paid total (after the line discount) split once over all of them.
            $copies = (int) $qty;
            $weights = [];
            $owners = [];
            for ($copy = 0; $copy < $copies; $copy++) {
                array_push($weights, ...$unitWeights);
                array_push($owners, ...$owner);
            }
            $shares = array_fill(0, count($children), 0);
            foreach (self::split($lineTotalBaisas, $weights) as $unit => $share) {
                $shares[$owners[$unit]] += $share;
            }

            return $shares;
        }

        $perOne = array_fill(0, count($children), 0);
        foreach (self::split($unitPriceBaisas, $unitWeights) as $unit => $share) {
            $perOne[$owner[$unit]] += $share;
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

    /**
     * Fix order 1 (C-13) — what a device-sent line PAID, the base of its
     * split: the line total less ALL its line-level discounts. A till sends a
     * line discount as a discount ROW aimed at the line (`line_index`, a
     * manual / rule / offer row alike) rather than in `line_discount_baisas`;
     * the rows are the money (discount_total = their sum). A device that also
     * fills the field describes the same money, so the line's discount is the
     * larger of the two, never their sum. Order-level rows (no line_index)
     * and comps are not spread. Never below 0.
     *
     * @param  array<string, mixed>  $line
     * @param  list<mixed>  $discounts  the order's discount rows
     */
    public static function paidOnWire(array $line, array $discounts, int $lineIndex): int
    {
        $rows = 0;
        foreach ($discounts as $rawRow) {
            $row = (array) $rawRow;
            if (isset($row['line_index']) && is_numeric($row['line_index']) && (int) $row['line_index'] === $lineIndex) {
                $rows += (int) ($row['amount_baisas'] ?? 0);
            }
        }

        return max(0, (int) ($line['line_total_baisas'] ?? 0) - max($rows, (int) ($line['line_discount_baisas'] ?? 0)));
    }
}
