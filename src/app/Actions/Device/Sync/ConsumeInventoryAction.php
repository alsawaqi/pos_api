<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\BranchProduct;
use App\Models\BranchStock;
use App\Models\Order;
use App\Models\ProductStockMovement;
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

            // Per-branch product-unit stock (retail / finished goods): adjust
            // the pos_branch_product.stock_qty counter when this product is
            // unit-tracked at the order's branch. Independent of the recipe
            // ingredient depletion below; NULL/absent = untracked -> no-op.
            $this->moveProductStock($order, (int) $item->product_id, $sign * $itemQty, $staffId, $at);

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

    /**
     * Adjust a product's per-branch unit stock. Only unit-tracked products
     * (a pos_branch_product row with a non-null stock_qty) move; untracked
     * products (NULL stock_qty, or no row) are unlimited / recipe-depleted
     * and skipped. Negative stock is allowed (mirrors the ingredient policy:
     * an oversell surfaces later in the inventory report).
     *
     * Phase D1 — every balance move also appends a signed sale_consumption
     * row to the PRODUCT ledger (pos_product_stock_movements), so device
     * sales show up in the merchant Stock dialog's history alongside the
     * portal's receive/allocate/transfer rows. branch_id is set (branch
     * side); the CENTRAL pos_product_stock balance is deliberately untouched
     * (its invariant sums only branch_id-NULL rows). NOT counted into
     * apply()'s return value — that remains the INGREDIENT movement count
     * (the sync ACK contract).
     */
    private function moveProductStock(Order $order, int $productId, float $qty, ?int $staffId, Carbon $at): void
    {
        if ($qty === 0.0) {
            return;
        }

        $row = BranchProduct::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('product_id', $productId)
            ->first();

        if ($row === null || $row->stock_qty === null) {
            return;
        }

        $row->stock_qty = (float) $row->stock_qty + $qty;
        $row->save();

        ProductStockMovement::create([
            'company_id' => (int) $order->company_id,
            'product_id' => $productId,
            'branch_id' => (int) $order->branch_id,
            'movement_type' => ProductStockMovement::TYPE_SALE_CONSUMPTION,
            'quantity' => number_format($qty, 3, '.', ''),
            'reference_type' => 'pos_orders',
            'reference_id' => (int) $order->id,
            'recorded_by_pos_staff_id' => $staffId,
            'occurred_at' => $at,
            'created_at' => now(),
        ]);
    }
}
