<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\BranchStock;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Phase 8.3 — atomic branch-stock consumption for a sale (blueprint §9.9 +
 * §16 "inventory deducts at payment completion").
 *
 * Reads each order line's frozen recipe_snapshot_json (and each add-on's
 * ingredient_snapshot_json), appends a signed pos_stock_movements row, and
 * moves pos_branch_stock by the same delta — keeping the ledger invariant
 * Σ(movements) == branch_stock.quantity. The caller (Pay/Void handler)
 * wraps this in its DB transaction, so a mid-loop failure rolls back both
 * the movements and the balance together.
 *
 * Negative stock is intentionally NOT blocked (§9.1.6): a sale against a
 * stale balance still settles and the shortfall surfaces later in the
 * inventory report.
 */
class ConsumeInventoryAction
{
    /** Deduct stock when an order is paid. Returns the number of movements written. */
    public function consume(Order $order): int
    {
        return $this->apply($order, -1);
    }

    /** Restore stock when a paid order is voided (the negation of consume). */
    public function reverse(Order $order): int
    {
        return $this->apply($order, 1);
    }

    private function apply(Order $order, int $sign): int
    {
        $order->loadMissing('items.addons');

        $branchId = (int) $order->branch_id;
        $staffId = $order->staff_id !== null ? (int) $order->staff_id : null;
        $at = $order->closed_at ?? now();
        $count = 0;

        foreach ($order->items as $item) {
            $itemQty = (float) $item->qty;

            foreach ((array) ($item->recipe_snapshot_json ?? []) as $ingredient) {
                $count += $this->move(
                    $branchId,
                    (int) $ingredient['ingredient_id'],
                    $sign * (float) $ingredient['qty'] * $itemQty,
                    (float) ($ingredient['unit_cost'] ?? 0),
                    StockMovement::TYPE_SALE_CONSUMPTION,
                    (int) $order->id,
                    $staffId,
                    $at,
                );
            }

            foreach ($item->addons as $addon) {
                $snapshot = $addon->ingredient_snapshot_json;
                if (! is_array($snapshot) || ! isset($snapshot['ingredient_id'])) {
                    continue;
                }
                $count += $this->move(
                    $branchId,
                    (int) $snapshot['ingredient_id'],
                    $sign * (float) ($snapshot['qty'] ?? 0) * $itemQty,
                    (float) ($snapshot['unit_cost'] ?? 0),
                    StockMovement::TYPE_ADDON_CONSUMPTION,
                    (int) $order->id,
                    $staffId,
                    $at,
                );
            }
        }

        return $count;
    }

    private function move(int $branchId, int $ingredientId, float $qty, float $unitCost, string $type, int $orderId, ?int $staffId, Carbon $at): int
    {
        if ($qty === 0.0) {
            return 0;
        }

        StockMovement::create([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
            'movement_type' => $type,
            'quantity' => number_format($qty, 3, '.', ''),
            'unit_cost_at_time' => number_format($unitCost, 3, '.', ''),
            'reference_type' => 'pos_orders',
            'reference_id' => $orderId,
            'recorded_by_pos_staff_id' => $staffId,
            'occurred_at' => $at,
            'created_at' => now(),
        ]);

        $stock = BranchStock::firstOrNew([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
        ]);
        $stock->quantity = (float) $stock->quantity + $qty;
        $stock->last_movement_at = now();
        $stock->save();

        return 1;
    }
}
