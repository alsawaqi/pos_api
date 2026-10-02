<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LAUNCH-P2 P2-6 — a fair count variance, kept fair after the count.
 *
 * A stock count compares what was counted with the book balance AT THE
 * COUNT MOMENT (every movement dated before counted_at). A movement dated
 * before a count can still reach the books AFTER the count was submitted:
 * an offline till sells at 21:55, the 22:00 count is submitted, the till
 * syncs at 22:30 (or a goods-received note is back-dated). Without help the
 * count would have booked the sold amount as a shortfall AND the late sale
 * would deduct it again.
 *
 * Called right after such a movement (and its balance change) is written,
 * in the same transaction, this folds it into the FIRST count of that
 * branch + ingredient dated after it — the first physical observation that
 * already reflected it:
 *
 *   1. a `count_correction` movement of the opposite sign, dated at the
 *      count, so the balance after the count stays what was counted plus
 *      later movements (later counts are untouched: the pair nets to zero
 *      before them);
 *   2. the line's expected_units += m and variance_units −= m (the fair
 *      variance), late_movement_units += m;
 *   3. the line's reconciliation_variance waste record follows the fair
 *      shortfall (resized, or created when a shortfall appears).
 *
 * pos_api runs it for device sales (pay and delivery hand-off), their voids
 * and late device counts; pos_merchant's FoldLateMovementIntoCountAction is
 * the same rule for portal movements (a back-dated goods-received note).
 * Written with the query builder only, so both copies read the same. A
 * movement in the count's own second already on the books when the count
 * was taken counted as before it; one arriving later is treated as after.
 */
final class FoldLateMovementIntoCount
{
    public const REFERENCE_TYPE = 'pos_stock_count_lines';

    /**
     * @param  string|float|int  $signedQuantity  the late movement's signed quantity
     * @return array{correction_id: int, stock_count_line_id: int, expected_units: string, variance_units: string}|null
     */
    public function handle(
        int $branchId,
        int $ingredientId,
        string|float|int $signedQuantity,
        string|float|int $unitCost,
        DateTimeInterface $occurredAt,
        ?int $recordedByUserId = null,
        ?int $recordedByStaffId = null,
    ): ?array {
        $m = BigDecimal::of((string) StockDecimal::quantity($signedQuantity));
        if ($m->isZero()) {
            return null;
        }

        $line = DB::table('pos_stock_count_lines')
            ->join('pos_stock_counts', 'pos_stock_counts.id', '=', 'pos_stock_count_lines.stock_count_id')
            ->where('pos_stock_counts.branch_id', $branchId)
            ->where('pos_stock_count_lines.ingredient_id', $ingredientId)
            ->where('pos_stock_counts.counted_at', '>', Carbon::instance($occurredAt))
            ->orderBy('pos_stock_counts.counted_at')
            ->orderBy('pos_stock_counts.id')
            ->lockForUpdate()
            ->first([
                'pos_stock_count_lines.*',
                'pos_stock_counts.counted_at as count_counted_at',
                'pos_stock_counts.uuid as count_uuid',
            ]);
        if ($line === null) {
            return null;
        }

        $now = now();
        $countedAt = Carbon::parse((string) $line->count_counted_at);
        $correction = $m->negated();

        $correctionId = (int) DB::table('pos_stock_movements')->insertGetId([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
            'movement_type' => 'count_correction',
            'quantity' => StockDecimal::quantity((string) $correction),
            'unit_cost_at_time' => StockDecimal::unitCost($unitCost),
            'reference_type' => self::REFERENCE_TYPE,
            'reference_id' => (int) $line->id,
            'recorded_by_user_id' => $recordedByUserId,
            'recorded_by_pos_staff_id' => $recordedByStaffId,
            'note' => sprintf(
                'Count correction: a movement dated %s reached the books after the stock count of %s (%s).',
                Carbon::instance($occurredAt)->toDateTimeString(),
                $countedAt->toDateTimeString(),
                $line->count_uuid,
            ),
            'occurred_at' => $countedAt,
            'created_at' => $now,
        ]);

        // The balance row exists: the late movement just moved it.
        DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->increment('quantity', (string) StockDecimal::quantity((string) $correction), [
                'last_movement_at' => $now,
                'updated_at' => $now,
            ]);

        $expected = self::decimal($line->expected_units)->plus($m);
        $variance = self::decimal($line->variance_units)->minus($m);
        $late = self::decimal($line->late_movement_units ?? 0)->plus($m);
        $wasteId = $this->followShortfall($line, $branchId, $ingredientId, $variance, $countedAt, $now);

        DB::table('pos_stock_count_lines')->where('id', $line->id)->update([
            'expected_units' => StockDecimal::quantity((string) $expected),
            'variance_units' => StockDecimal::quantity((string) $variance),
            'late_movement_units' => StockDecimal::quantity((string) $late),
            'waste_record_id' => $wasteId,
        ]);

        return [
            'correction_id' => $correctionId,
            'stock_count_line_id' => (int) $line->id,
            'expected_units' => (string) StockDecimal::quantity((string) $expected),
            'variance_units' => (string) StockDecimal::quantity((string) $variance),
        ];
    }

    /**
     * The line's reconciliation waste = the fair shortfall (0 when the fair
     * variance is not a shortfall). Returns the waste record id, if any.
     */
    private function followShortfall(object $line, int $branchId, int $ingredientId, BigDecimal $variance, Carbon $countedAt, Carbon $now): ?int
    {
        $shortfall = $variance->isNegative() ? $variance->negated() : BigDecimal::zero();

        $wasteId = $line->waste_record_id !== null ? (int) $line->waste_record_id : null;
        if ($wasteId === null && $line->stock_movement_id !== null) {
            // Pre-P2 lines: the original shortfall movement points at it.
            $ref = DB::table('pos_stock_movements')
                ->where('id', (int) $line->stock_movement_id)
                ->where('movement_type', 'waste')
                ->whereIn('reference_type', ['App\\Models\\WasteRecord', 'pos_waste_records'])
                ->value('reference_id');
            $wasteId = $ref !== null ? (int) $ref : null;
        }

        if ($wasteId !== null) {
            DB::table('pos_waste_records')->where('id', $wasteId)->update([
                'quantity' => StockDecimal::quantity((string) $shortfall),
                'updated_at' => $now,
            ]);

            return $wasteId;
        }

        if (! $shortfall->isPositive()) {
            return null;
        }

        return (int) DB::table('pos_waste_records')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
            'quantity' => StockDecimal::quantity((string) $shortfall),
            'reason' => 'reconciliation_variance',
            'unit_at_set' => (string) DB::table('pos_ingredients')->where('id', $ingredientId)->value('unit'),
            'unit_cost_at_time' => StockDecimal::unitCost($line->unit_cost_at_time ?? 0),
            'notes' => 'Stock count shortfall after a late movement was folded into the count.',
            'occurred_at' => $countedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function decimal(string|float|int|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }

        return BigDecimal::of((string) $value)->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }
}
