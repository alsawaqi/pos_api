<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\BranchProduct;
use App\Models\BranchStock;
use App\Models\Order;
use App\Models\ProductStockMovement;
use App\Models\StockMovement;
use App\Support\Recipes\OrderTypes;
use App\Support\StockDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *
 * LAUNCH packaging add-on — stock by order type. consume() stamps the
 * order's stock bucket (pos_orders.stock_order_type: the order's FINAL type,
 * `car` → to_go) and freezes the merchant's per-order packaging for it
 * (packaging_snapshot_json), in the caller's transaction. Every copied line
 * whose "Used for" mask excludes that bucket is then left out — recipe,
 * frozen components, add-on stock lines, the legacy add-on ingredient and a
 * product-as-add-on's recipe and components — before base and deltas are
 * merged. The packaging moves ONCE per order (× 1, not × items), as
 * sale_consumption with an "order packaging" note. reverse() uses only the
 * stamp and the frozen packaging, so a void restores exactly what was
 * taken; an order stocked before this release (no stamp) is never
 * filtered and has no packaging. The legacy live-components fallback (no
 * component copy) is never filtered either: old code took every component.
 * Missing packaging stock goes below zero like any sale: a paid sale is
 * never refused.
 */
class ConsumeInventoryAction
{
    /** pos_stock_movements / pos_product_stock_movements note of per-order packaging ("order packaging (to_go)"). */
    public const PACKAGING_NOTE = 'order packaging';

    /**
     * LAUNCH-P2 P2-6 — whether a stock count of the order's branch is dated
     * after this order's movements (one query per order; most sales sync
     * before any later count, so the per-ingredient fold is then skipped).
     */
    private bool $countAfterSale = false;

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
        // LAUNCH-P2 P2-6 — the ledger time is the SALE time, never the sync
        // time: the device's paid_at (closed_at), or for a delivery order
        // handed off before payment the device's delivered_at. A void
        // reverses at the same moment. Stock counts read "balance as of T"
        // from this column.
        $at = $order->closed_at ?? $order->delivery_punched_at ?? now();
        $count = 0;
        $this->countAfterSale = DB::table('pos_stock_counts')
            ->where('branch_id', $branchId)
            ->where('counted_at', '>', $at)
            ->exists();

        // LAUNCH packaging add-on — the bucket the lines are filtered by:
        // stamped (with the packaging) when stock is taken, read back on void.
        $bit = $this->stockBit($order, $sign);

        // P-G2 — physical-item components. New orders carry them FROZEN on
        // the line (component_snapshot_json, written at create like the
        // recipe) so pay and void move the exact set the order knew. The
        // live bulk read remains ONLY as the fallback for legacy lines
        // written before the freeze column existed (snapshot === null).
        // ONE level only: components have no components.
        $legacyProductIds = $order->items
            ->filter(static fn ($i): bool => $i->component_snapshot_json === null)
            ->pluck('product_id')
            ->filter()
            ->all();
        $componentsByProduct = DB::table('pos_product_components')
            ->whereIn('product_id', $legacyProductIds ?: [0])
            ->get()
            ->groupBy('product_id');

        // LAUNCH-P3 fix order 1 K7 — the line product's shelf moves only when
        // it is a shelf product (unit / cooked), read live (soft-deleted rows
        // included), the same rule as the pos_admin reversal copy: a product
        // switched to untracked or made-to-order can keep a leftover
        // stock_qty, which a sale or its void must never move.
        $shelfModes = DB::table('pos_products')
            ->whereIn('id', $order->items->pluck('product_id')->filter()->all() ?: [0])
            ->pluck('stock_mode', 'id');

        foreach ($order->items as $item) {
            $itemQty = (float) $item->qty;

            // Per-branch product-unit stock (retail / finished goods): adjust
            // the pos_branch_product.stock_qty counter when this product is
            // unit-tracked at the order's branch. Independent of the recipe
            // ingredient depletion below; NULL/absent = untracked -> no-op.
            if (in_array((string) ($shelfModes[(int) $item->product_id] ?? ''), ['unit', 'cooked'], true)) {
                $this->moveProductStock($order, (int) $item->product_id, $sign * $itemQty, $staffId, $at);
            }

            // PD3b — merge the frozen recipe + live components with the
            // option add/remove deltas (frozen consumption_snapshot_json),
            // clamped at zero per ingredient/product: a removal reduces what
            // the parent would have used but never restocks. Attribution:
            // up to the base amount stays sale/component; the surplus above
            // it is option consumption. With no option lines this reduces
            // exactly to the pre-PD3b behaviour. The SAME merge runs on
            // consume and reverse (sign applies last) — void symmetry holds.
            [$ingredientPlan, $productPlan] = $this->mergeItemConsumption($item, $componentsByProduct, $bit);

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

            foreach ($this->addonIngredients($item, $bit) as $ingredient) {
                $count += $this->move($branchId, $ingredient['ingredient_id'],
                    $sign * $ingredient['qty'] * $itemQty, $ingredient['unit_cost'],
                    StockMovement::TYPE_ADDON_CONSUMPTION, (int) $order->id, $staffId, $at);
            }

            foreach ($item->addons as $addon) {
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
                    }

                    // PD3b — the linked product's OWN frozen components
                    // (its packaging: the side-fries box). Absent on
                    // pre-PD3b snapshots -> no-op.
                    foreach ((array) ($productSnapshot['components'] ?? []) as $component) {
                        if (! is_array($component) || ! OrderTypes::applies($component, $bit)) {
                            continue;
                        }
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

        // LAUNCH packaging add-on — the order's frozen packaging, once.
        $count += $this->movePackaging($order, $sign, $staffId, $at);

        return $count;
    }

    /**
     * LAUNCH packaging add-on — the bit of the order's stock bucket (null =
     * take every line). On consume an unstamped order is stamped with its
     * final type's bucket and its packaging is frozen in the same write; a
     * stamped order (and every void) uses the stamp as it is.
     */
    private function stockBit(Order $order, int $sign): ?int
    {
        if ($sign < 0 && $order->stock_order_type === null) {
            $bucket = OrderTypes::bucket($order->order_type !== null ? (string) $order->order_type : null);
            if ($bucket !== null) {
                $order->forceFill([
                    'stock_order_type' => $bucket,
                    'packaging_snapshot_json' => $order->packaging_snapshot_json ?? $this->packagingSnapshot($order, $bucket),
                ])->save();
            }
        }

        return OrderTypes::bit($order->stock_order_type !== null ? (string) $order->stock_order_type : null);
    }

    /**
     * LAUNCH packaging add-on — the merchant's per-order packaging for this
     * bucket, frozen: {order_type, lines: [{type: ingredient, ingredient_id,
     * qty, unit, unit_cost} | {type: product, product_id, qty}]}; null when
     * there is none, or when no top-level line is left (a fully cancelled
     * bill takes no packaging). Read by the ORDER's company; a line naming
     * another company's item, or a prep item, is skipped and logged —
     * never failing the sale. Ingredient costs are the live ones.
     *
     * @return array{order_type: string, lines: list<array<string, mixed>>}|null
     */
    private function packagingSnapshot(Order $order, string $bucket): ?array
    {
        $sold = $order->items->contains(static fn ($item): bool => $item->parent_order_item_id === null
            && (float) $item->qty > 0 && $item->status !== 'void');
        if (! $sold) {
            return null;
        }

        $companyId = (int) $order->company_id;
        $rows = DB::table('pos_order_packaging_lines')
            ->where('company_id', $companyId)
            ->where('order_type', $bucket)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $ingredients = DB::table('pos_ingredients')
            ->whereIn('id', $rows->pluck('ingredient_id')->filter()->all() ?: [0])
            ->get()
            ->keyBy('id');
        $products = DB::table('pos_products')
            ->whereIn('id', $rows->pluck('product_id')->filter()->all() ?: [0])
            ->pluck('company_id', 'id');

        $lines = [];
        $skipped = [];
        foreach ($rows as $row) {
            if ($row->ingredient_id !== null) {
                $ingredient = $ingredients->get((int) $row->ingredient_id);
                if ($ingredient === null || (int) $ingredient->company_id !== $companyId || (bool) ($ingredient->is_prep ?? false)) {
                    $skipped[] = (int) $row->id;

                    continue;
                }
                $lines[] = [
                    'type' => 'ingredient',
                    'ingredient_id' => (int) $row->ingredient_id,
                    'qty' => (float) $row->quantity,
                    'unit' => $row->unit !== null && $row->unit !== '' ? (string) $row->unit : ($ingredient->unit ?? null),
                    'unit_cost' => (float) StockDecimal::exact($ingredient->default_unit_cost ?? 0),
                ];
            } elseif ($row->product_id !== null) {
                if ((int) ($products[(int) $row->product_id] ?? 0) !== $companyId) {
                    $skipped[] = (int) $row->id;

                    continue;
                }
                $lines[] = ['type' => 'product', 'product_id' => (int) $row->product_id, 'qty' => (float) $row->quantity];
            }
        }
        if ($skipped !== []) {
            try {
                logger()->warning('Order packaging lines skipped: another company\'s item or a prep item', [
                    'order_id' => (int) $order->id, 'company_id' => $companyId, 'packaging_line_ids' => $skipped,
                ]);
            } catch (\Throwable) {
                // Best-effort; a sale never fails over logging.
            }
        }

        return $lines === [] ? null : ['order_type' => $bucket, 'lines' => $lines];
    }

    /**
     * LAUNCH packaging add-on — move the order's frozen packaging once
     * (sign −1 consume, +1 void). Returns the ingredient movements written.
     */
    private function movePackaging(Order $order, int $sign, ?int $staffId, Carbon $at): int
    {
        $snapshot = $order->packaging_snapshot_json;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        if (! is_array($snapshot) || ! is_array($snapshot['lines'] ?? null)) {
            return 0;
        }

        $note = self::PACKAGING_NOTE.' ('.(string) ($snapshot['order_type'] ?? $order->stock_order_type).')';
        $count = 0;
        foreach ($snapshot['lines'] as $line) {
            if (! is_array($line)) {
                continue;
            }
            if (($line['type'] ?? '') === 'ingredient' && isset($line['ingredient_id'])) {
                $count += $this->move((int) $order->branch_id, (int) $line['ingredient_id'], $sign * (float) ($line['qty'] ?? 0),
                    (float) ($line['unit_cost'] ?? 0), StockMovement::TYPE_SALE_CONSUMPTION, (int) $order->id, $staffId, $at, $note);
            } elseif (($line['type'] ?? '') === 'product' && isset($line['product_id'])) {
                $this->moveProductStock($order, (int) $line['product_id'], $sign * (float) ($line['qty'] ?? 0), $staffId, $at, $note);
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
     * LAUNCH packaging add-on — copied lines whose "Used for" mask excludes
     * $bit are dropped BEFORE base and deltas are summed, so a removal
     * clamps against the same subset (null = keep every line). The legacy
     * live-components read is never filtered.
     *
     * @param  Collection<int|string, mixed>  $componentsByProduct
     * @return array{0: array<int, array{sale: float, option: float, unit_cost: float}>, 1: array<int, array{component: float, option: float}>}
     */
    private function mergeItemConsumption(mixed $item, $componentsByProduct, ?int $bit = null): array
    {
        $ingredients = [];
        foreach ((array) ($item->recipe_snapshot_json ?? []) as $line) {
            if (! OrderTypes::applies((array) $line, $bit)) {
                continue;
            }
            $id = (int) $line['ingredient_id'];
            $ingredients[$id]['base'] = (float) ($ingredients[$id]['base'] ?? 0) + (float) $line['qty'];
            $ingredients[$id]['unit_cost'] = (float) ($line['unit_cost'] ?? 0);
        }

        $products = [];
        if (is_array($item->component_snapshot_json)) {
            // Frozen at create — pay/void replay the exact component set the
            // order was written with ([] = genuinely no components).
            foreach ($item->component_snapshot_json as $component) {
                if (! OrderTypes::applies((array) $component, $bit)) {
                    continue;
                }
                $id = (int) ($component['product_id'] ?? 0);
                $products[$id]['base'] = (float) ($products[$id]['base'] ?? 0) + (float) ($component['qty'] ?? 0);
            }
        } else {
            // Legacy line (pre-freeze): live read, the historical behaviour.
            foreach ($componentsByProduct->get($item->product_id) ?? [] as $component) {
                $id = (int) $component->component_product_id;
                $products[$id]['base'] = (float) ($products[$id]['base'] ?? 0) + (float) $component->quantity;
            }
        }

        foreach ($item->addons as $addon) {
            foreach ((array) ($addon->consumption_snapshot_json ?? []) as $line) {
                if (! OrderTypes::applies((array) $line, $bit)) {
                    continue;
                }
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

        // Plans round to LEDGER precision (ingredients 4dp since LAUNCH-P2,
        // product pieces 3dp): raw double sums can leave ~1e-16 residue when
        // removal deltas decimal-equal the base (e.g. 0.8 vs 0.7+0.1), which
        // would slip past move()'s zero guard as a junk zero row and inflate
        // the sync ACK movement count.
        $ingredientPlan = [];
        foreach ($ingredients as $id => $amounts) {
            $base = (float) ($amounts['base'] ?? 0);
            $total = round(max(0.0, $base + (float) ($amounts['delta'] ?? 0)), StockDecimal::QUANTITY_SCALE);
            $sale = round(min($base, $total), StockDecimal::QUANTITY_SCALE);
            $ingredientPlan[$id] = [
                'sale' => $sale,
                'option' => round($total - $sale, StockDecimal::QUANTITY_SCALE),
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

    /**
     * Frozen ingredients per unit, including clamped option add/remove deltas. No catalogue reads.
     *
     * LAUNCH packaging add-on — only the lines used for $orderType (the
     * order's current type: an unpaid table bill is dine in, a QR quick
     * order quick); null = every line.
     */
    public function frozenIngredients(mixed $item, ?string $orderType = null): array
    {
        $item->loadMissing('addons');
        $bit = OrderTypes::bit(OrderTypes::bucket($orderType));
        [$plan] = $this->mergeItemConsumption($item, collect(), $bit);
        $units = [];
        foreach ((array) $item->recipe_snapshot_json as $line) {
            $units[(int) $line['ingredient_id']] = (string) ($line['unit'] ?? '');
        }
        foreach ($item->addons as $addon) {
            foreach ((array) $addon->consumption_snapshot_json as $line) {
                if (isset($line['ingredient_id'])) {
                    $units[(int) $line['ingredient_id']] ??= (string) ($line['unit'] ?? '');
                }
            }
        }
        $rows = [];
        foreach ($plan as $id => $parts) {
            foreach (['sale', 'option'] as $kind) {
                $rows[] = ['ingredient_id' => (int) $id, 'qty' => $parts[$kind],
                    'unit' => $units[$id] ?? '', 'unit_cost' => $parts['unit_cost']];
            }
        }

        return array_merge($rows, $this->addonIngredients($item, $bit));
    }

    /**
     * Classic and product add-ons share this resolution with cancellation waste.
     * LAUNCH packaging add-on — lines whose mask excludes $bit are left out.
     */
    private function addonIngredients(mixed $item, ?int $bit = null): array
    {
        $rows = [];
        foreach ($item->addons as $addon) {
            $snapshot = $addon->ingredient_snapshot_json;
            if (! is_array($addon->consumption_snapshot_json) && is_array($snapshot) && isset($snapshot['ingredient_id'])
                && OrderTypes::applies($snapshot, $bit)) {
                $rows[] = $snapshot;
            }
            $product = $addon->product_snapshot_json;
            if (is_array($product) && isset($product['product_id']) && ($product['stock_mode'] ?? '') === 'ingredient') {
                foreach ((array) ($product['recipe'] ?? []) as $ingredient) {
                    if (OrderTypes::applies((array) $ingredient, $bit)) {
                        $rows[] = $ingredient;
                    }
                }
            }
        }

        return array_map(static fn (array $row): array => [
            'ingredient_id' => (int) $row['ingredient_id'], 'qty' => (float) ($row['qty'] ?? 0),
            'unit' => (string) ($row['unit'] ?? ''), 'unit_cost' => (float) ($row['unit_cost'] ?? 0),
        ], $rows);
    }

    private function move(int $branchId, int $ingredientId, float $qty, float $unitCost, string $type, int $orderId, ?int $staffId, Carbon $at, ?string $note = null): int
    {
        // Round to ledger precision (4dp) ONCE, then use the SAME value for
        // the movement row AND the balance delta. (plan × fractional item
        // qty) can carry further decimals; writing a rounded quantity to the
        // movement while adding the raw float to the balance would drift
        // Σ(movements) from branch_stock over time. The frozen unit cost keeps
        // its 6 decimals (LAUNCH-P2: never round a per-unit cost).
        $qty = round($qty, StockDecimal::QUANTITY_SCALE);
        if ($qty === 0.0) {
            return 0;
        }
        $quantity = (string) StockDecimal::quantity($qty);

        StockMovement::create([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost_at_time' => StockDecimal::unitCost($unitCost),
            'reference_type' => 'pos_orders',
            'reference_id' => $orderId,
            'recorded_by_pos_staff_id' => $staffId,
            'occurred_at' => $at,
            'created_at' => now(),
        ] + ($note !== null ? ['note' => $note] : []));

        // Atomic SQL-expression increment (quantity = quantity + δ), matching
        // the merchant portal's WriteStockMovementAction and the production
        // path's locked rows. The previous read-modify-write (firstOrNew +
        // absolute save) could lose a concurrent settle's delta on the same
        // (branch, ingredient) row — both movement rows persisted but only
        // one balance change survived, silently breaking Σ(movements) ==
        // quantity. firstOrCreate is race-safe (savepoint + retry on the
        // unique constraint); updated_at must move — the device branch_stock
        // delta slice keys on it.
        $stock = BranchStock::query()->firstOrCreate(
            ['branch_id' => $branchId, 'ingredient_id' => $ingredientId],
            ['quantity' => 0, 'last_movement_at' => now()],
        );
        BranchStock::query()
            ->whereKey($stock->getKey())
            ->toBase()
            ->increment('quantity', $quantity, [
                'last_movement_at' => now(),
                'updated_at' => now(),
            ]);

        // LAUNCH-P2 P2-6 — a sale (or its void) dated before a stock count of
        // this branch that only now reaches the server is folded into that
        // count, so the count's variance stays fair and the balance after
        // the count is not moved twice.
        if ($this->countAfterSale) {
            (new FoldLateMovementIntoCount)->handle(
                $branchId,
                $ingredientId,
                $quantity,
                $unitCost,
                $at,
                null,
                $staffId,
            );
        }

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
        // Same 3dp-once rule as move() — keep the product ledger row and the
        // pos_branch_product.stock_qty balance in lockstep on fractional sales.
        $qty = round($qty, 3);
        if ($qty === 0.0) {
            return;
        }

        // Atomic SQL-expression increment — same lost-update fix as move().
        // The whereNotNull keeps the "NULL stock_qty / no row = not unit-
        // tracked here" no-op semantics (affected 0 → no ledger row either),
        // and the explicit updated_at bump preserves the delta trigger that
        // re-emits this product's shelf count to devices.
        $affected = BranchProduct::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('product_id', $productId)
            ->whereNotNull('stock_qty')
            ->toBase()
            ->increment('stock_qty', $qty, ['updated_at' => now()]);

        if ($affected === 0) {
            return;
        }

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
