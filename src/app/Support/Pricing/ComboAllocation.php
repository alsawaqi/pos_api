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
 * Fix order 2 (C-18) — never one entry per unit: every unit but the last
 * gets the same rounded share for its weight, so a child's share is its
 * unit count × that share (the last child takes the remainder); the
 * cumulative fallback telescopes over each child's run of units, per combo.
 * The same shares as listing every unit, at any quantity. A line of more
 * than {@see MAX_COPIES} combos (the pricing check flags it) that needs the
 * fallback splits by the per-one shares instead, so no request loops over
 * an absurd quantity.
 *
 * Golden example (work order §3 Part A item 6): a Family box paid 5.000 with
 * 2 × Burger 2.000, Fries 1.000, Cola 1.000 → 1.667 + 1.667 + 0.833 + 0.833.
 * The same algorithm is in mithqal_pricing (Part B); the shared vectors are
 * in tests/Fixtures/combo_allocation_vectors.json.
 */
final class ComboAllocation
{
    /** The largest line quantity a device may send (more is flagged, never refused). */
    public const MAX_COPIES = 9999;

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
        // Each child is a run of units of one weight: a whole item quantity
        // is that many units, a fractional one is one unit of weight × qty.
        $runs = [];
        foreach (array_values($children) as $child) {
            $qty = (float) $child['qty'];
            $runs[] = $qty >= 1 && $qty == floor($qty)
                ? [(int) $qty, (int) $child['weight']]
                : [1, (int) round($child['weight'] * $qty)];
        }
        $qty = (float) $lineQty;
        if ($qty >= 1 && $qty == floor($qty)) {
            // Every unit of every combo / meal on the line, in order: the
            // paid total (after the line discount) split once over all of them.
            $shares = self::splitRuns($lineTotalBaisas, $runs, (int) $qty, self::MAX_COPIES);
            if ($shares !== null) {
                return $shares;
            }
        }

        return self::split($lineTotalBaisas, self::splitRuns($unitPriceBaisas, $runs, 1, 1) ?? []);
    }

    /**
     * {@see split()} over $copies copies of these runs of units ([count,
     * weight] each), one total per run — without listing the units. Null
     * when the cumulative fallback is needed for more than $maxLoopCopies
     * copies.
     *
     * @param  list<array{0: int, 1: int}>  $runs
     * @return list<int>|null
     */
    private static function splitRuns(int $amount, array $runs, int $copies, int $maxLoopCopies): ?array
    {
        $runs = array_map(static fn (array $run): array => [$run[0], max(0, $run[1])], $runs);
        $perCopy = 0;
        foreach ($runs as [$count, $weight]) {
            $perCopy += $count * $weight;
        }
        if ($perCopy <= 0) {
            $runs = array_map(static fn (array $run): array => [$run[0], 1], $runs);
            $perCopy = array_sum(array_column($runs, 0));
        }
        $total = $copies * $perCopy;
        $last = count($runs) - 1;
        $shares = [];
        $given = 0;
        foreach ($runs as $i => [$count, $weight]) {
            if ($i === $last) {
                break;
            }
            $shares[$i] = $copies * $count * self::roundedShare($amount, $weight, $total);
            $given += $shares[$i];
        }
        $lastUnits = $copies * $runs[$last][0];
        $lastUnit = $amount - $given - ($lastUnits - 1) * self::roundedShare($amount, $runs[$last][1], $total);
        $shares[$last] = $amount - $given;
        if ($amount < 0 || $lastUnit >= 0) {
            return $shares;
        }
        if ($copies > $maxLoopCopies) {
            return null;
        }
        // The cumulative split: a run's units take round(P·C_end/W) −
        // round(P·C_start/W) between them (the units in between cancel).
        $shares = array_fill(0, count($runs), 0);
        for ($copy = 0; $copy < $copies; $copy++) {
            $start = $copy * $perCopy;
            foreach ($runs as $i => [$count, $weight]) {
                $end = $start + $count * $weight;
                $shares[$i] += self::roundedShare($amount, $end, $total) - self::roundedShare($amount, $start, $total);
                $start = $end;
            }
        }

        return $shares;
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
