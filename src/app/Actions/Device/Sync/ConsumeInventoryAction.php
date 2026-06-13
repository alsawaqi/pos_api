<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\BranchProduct;
use App\Models\BranchStock;
use App\Models\Order;
use App\Models\ProductStockMovement;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        // P-G2 — physical-item components per product, bulk-loaded once.
        // ONE level only: components have no components.
        $componentsByProduct = DB::table('pos_product_components')
            ->whereIn('product_id', $order->items->pluck('product_id')->filter()->all() ?: [0])
            ->get()
            ->groupBy('product_id');

        foreach ($order->items as $item) {
            $itemQty = (float) $item->qty;

            // Per-branch product-unit stock (retail / finished goods): adjust
            // the pos_branch_product.stock_qty counter when this product is
            // unit-tracked at the order's branch. Independent of the recipe
            // ingredient depletion below; NULL/absent = untracked -> no-op.
            $this->moveProductStock($order, (int) $item->product_id, $sign * $itemQty, $staffId, $at);

            // PD3b — merge the frozen recipe + live components with the
            // option add/remove deltas (frozen consumption_snapshot_json),
            // clamped at zero per ingredient/product: a removal reduces what
            // the parent would have used but never restocks. Attribution:
            // up to the base amount stays sale/component; the surplus above
            // it is option consumption. With no option lines this reduces
            // exactly to the pre-PD3b behaviour. The SAME merge runs on
            // consume and reverse (sign applies last) — void symmetry holds.
            [$ingredientPlan, $productPlan] = $this->mergeItemConsumption($item, $componentsByProduct);

            // P-G2 — the product's physical items (coffee = 1 x cup + 1 x
            // lid) leave the branch's unit stock with every sale and come
            // back on void (the sign flips). Same no-op rule: a component
            // not unit-tracked at this branch doesn't move.
            foreach ($productPlan as $productId => $parts) {
                $this->moveProductStock(
                    $order,
                    (int) $productId,
                    $sign * $parts['component'] * $itemQty,
                    $staffId,
                    $at,
                    'component of #'.$item->product_id,
                );
                $this->moveProductStock(
                    $order,
                    (int) $productId,
                    $sign * $parts['option'] * $itemQty,
                    $staffId,
                    $at,
                    'option consumption',
                );
            }

            foreach ($ingredientPlan as $ingredientId => $parts) {
                $count += $this->move(
                    $branchId,
                    (int) $ingredientId,
                    $sign * $parts['sale'] * $itemQty,
                    $parts['unit_cost'],
                    StockMovement::TYPE_SALE_CONSUMPTION,
                    (int) $order->id,
                    $staffId,
                    $at,
                );
                $count += $this->move(
                    $branchId,
                    (int) $ingredientId,
                    $sign * $parts['option'] * $itemQty,
                    $parts['unit_cost'],
                    StockMovement::TYPE_ADDON_CONSUMPTION,
                    (int) $order->id,
                    $staffId,
                    $at,
                );
            }

            foreach ($item->addons as $addon) {
                // Classic single-ingredient add-on ("extra shot"). Skipped
                // when the addon carries PD3b consumption lines (create
                // already nulls the trio then — this guard keeps any old
                // double-written row from double-counting).
                $snapshot = $addon->ingredient_snapshot_json;
                if (is_array($addon->consumption_snapshot_json)) {
                    $snapshot = null;
                }
                if (is_array($snapshot) && isset($snapshot['ingredient_id'])) {
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

                // P-G3 — product-as-add-on: consume the FROZEN product by
                // its type (one selection = 1 x the parent line qty).
                // cooked/unit: branch shelf moves; made-to-order: the
                // frozen recipe; untracked: nothing. Same pool as the
                // standalone tile, so both grey out together at zero.
                $productSnapshot = $addon->product_snapshot_json;
                if (is_array($productSnapshot) && isset($productSnapshot['product_id'])) {
                    $mode = (string) ($productSnapshot['stock_mode'] ?? '');
                    if ($mode === 'unit' || $mode === 'cooked') {
                        $this->moveProductStock(
                            $order,
                            (int) $productSnapshot['product_id'],
                            $sign * $itemQty,
                            $staffId,
                            $at,
                            'sold as add-on',
                        );
                    } elseif ($mode === 'ingredient') {
                        foreach ((array) ($productSnapshot['recipe'] ?? []) as $ingredient) {
                            $count += $this->move(
                                $branchId,
                                (int) $ingredient['ingredient_id'],
                                $sign * (float) ($ingredient['qty'] ?? 0) * $itemQty,
                                (float) ($ingredient['unit_cost'] ?? 0),
                                StockMovement::TYPE_ADDON_CONSUMPTION,
                                (int) $order->id,
                                $staffId,
                                $at,
                            );
                        }
                    }

                    // PD3b — the linked product's OWN frozen components
                    // (its packaging: the side-fries box). Absent on
                    // pre-PD3b snapshots -> no-op.
                    foreach ((array) ($productSnapshot['components'] ?? []) as $component) {
                        $this->moveProductStock(
                            $order,
                            (int) ($component['product_id'] ?? 0),
                            $sign * (float) ($component['qty'] ?? 0) * $itemQty,
                            $staffId,
                            $at,
                            'component of add-on #'.$productSnapshot['product_id'],
                        );
                    }
                }
            }
        }

        return $count;
    }

    /**
     * PD3b — fold one order item's BASE consumption (frozen recipe +
     * live components) together with its options' add/remove deltas
     * (frozen consumption_snapshot_json on each addon row) into a
     * per-ref plan, all per ONE item unit:
     *
     *   total  = max(0, base + Σ deltas)        — a removal never restocks
     *   sale   = min(base, total)               — attributed to the recipe /
     *   option = total - sale                     component; surplus above
     *                                             the base is option usage
     *
     * Reuses the two existing movement types (sale_consumption /
     * addon_consumption) so every consumption reader (Loss & Waste
     * shortfall, consumption report, branch report) stays honest without
     * changes. unit_cost prefers the recipe's frozen cost, falling back
     * to the option line's.
     *
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $componentsByProduct
     * @return array{0: array<int, array{sale: float, option: float, unit_cost: float}>, 1: array<int, array{component: float, option: float}>}
     */
    private function mergeItemConsumption(mixed $item, $componentsByProduct): array
    {
        $ingredients = [];
        foreach ((array) ($item->recipe_snapshot_json ?? []) as $line) {
            $id = (int) $line['ingredient_id'];
            $ingredients[$id]['base'] = (float) ($ingredients[$id]['base'] ?? 0) + (float) $line['qty'];
            $ingredients[$id]['unit_cost'] = (float) ($line['unit_cost'] ?? 0);
        }

        $products = [];
        foreach ($componentsByProduct->get($item->product_id) ?? [] as $component) {
            $id = (int) $component->component_product_id;
            $products[$id]['base'] = (float) ($products[$id]['base'] ?? 0) + (float) $component->quantity;
        }

        foreach ($item->addons as $addon) {
            foreach ((array) ($addon->consumption_snapshot_json ?? []) as $line) {
                $delta = (($line['direction'] ?? 'add') === 'remove' ? -1.0 : 1.0) * (float) ($line['qty'] ?? 0);
                if (($line['type'] ?? '') === 'ingredient' && isset($line['ingredient_id'])) {
                    $id = (int) $line['ingredient_id'];
                    $ingredients[$id]['delta'] = (float) ($ingredients[$id]['delta'] ?? 0) + $delta;
                    if (! isset($ingredients[$id]['unit_cost'])) {
                        $ingredients[$id]['unit_cost'] = (float) ($line['unit_cost'] ?? 0);
                    }
                } elseif (($line['type'] ?? '') === 'product' && isset($line['product_id'])) {
                    $id = (int) $line['product_id'];
                    $products[$id]['delta'] = (float) ($products[$id]['delta'] ?? 0) + $delta;
                }
            }
        }

        // Plans round to LEDGER precision (3dp): raw double sums can leave
        // ~1e-16 residue when removal deltas decimal-equal the base (e.g.
        // 0.8 vs 0.7+0.1), which would slip past move()'s zero guard as a
        // junk 0.000 row and inflate the sync ACK movement count.
        $ingredientPlan = [];
        foreach ($ingredients as $id => $amounts) {
            $base = (float) ($amounts['base'] ?? 0);
            $total = round(max(0.0, $base + (float) ($amounts['delta'] ?? 0)), 3);
            $sale = round(min($base, $total), 3);
            $ingredientPlan[$id] = [
                'sale' => $sale,
                'option' => round($total - $sale, 3),
                'unit_cost' => (float) ($amounts['unit_cost'] ?? 0),
            ];
        }

        $productPlan = [];
        foreach ($products as $id => $amounts) {
            $base = (float) ($amounts['base'] ?? 0);
            $total = round(max(0.0, $base + (float) ($amounts['delta'] ?? 0)), 3);
            $component = round(min($base, $total), 3);
            $productPlan[$id] = [
                'component' => $component,
                'option' => round($total - $component, 3),
            ];
        }

        return [$ingredientPlan, $productPlan];
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
    private function moveProductStock(Order $order, int $productId, float $qty, ?int $staffId, Carbon $at, ?string $note = null): void
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
            // P-G2 — component consumption names its parent product so the
            // merchant Stock history reads "why did cups leave".
            'note' => $note,
            'occurred_at' => $at,
            'created_at' => now(),
        ]);
    }
}
