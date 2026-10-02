<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Actions\Device\Sync\Handlers\CreateOrderHandler;
use App\Actions\Qr\OrderLineSnapshotter;
use App\Models\AddOn;
use App\Models\Product;
use App\Support\StockDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 — what an order line copies ("freezes") about stock use when it
 * is written: its product's recipe and its add-on options' stock-use lines.
 * Shared by device orders ({@see CreateOrderHandler}, at the sale moment)
 * and QR orders and rounds ({@see OrderLineSnapshotter}, live).
 *
 *  - P3-7: only a made-to-order product (stock_mode 'ingredient') copies a
 *    recipe. A cooked product consumed it at production, a unit product is
 *    bought in, and an untracked product never deducts a leftover recipe.
 *  - P3-6: the recipe in force at the given moment ({@see RecipeInForce}).
 *  - P3-4: prep items are exploded into their raw ingredients
 *    ({@see PrepExploder}). The copy keeps its shape — raw ingredient lines
 *    {ingredient_id, qty, unit, unit_cost} per ONE unit — so pay and void
 *    (ConsumeInventoryAction), the pos_admin reversal copy and the portal's
 *    cost of goods read it unchanged.
 *
 * One instance per order write: it caches what it read.
 */
final class RecipeCopy
{
    private readonly PrepExploder $exploder;

    private readonly RecipeInForce $recipes;

    public function __construct(int $companyId, ?CarbonInterface $at = null)
    {
        $this->exploder = new PrepExploder($companyId);
        $this->recipes = new RecipeInForce($at);
    }

    /**
     * The recipe an order line of this product copies, per ONE unit, or null
     * when the line deducts no ingredients.
     *
     * P-G1: cooked products consume their recipe at PRODUCTION (the kitchen
     * batch already deducted the ingredients when it started); at sale only
     * the branch shelf count moves, so copying NO recipe keeps pay/void from
     * double-consuming the ingredients.
     *
     * PD2: unit (ready / bought-in) products are PURCHASED, never made — their
     * cost reaches net profit through the stock-purchase expense booked at
     * receive, and only the shelf count moves at sale. A stale recipe left
     * over from a made-to-order past must not be copied: it would consume
     * ingredients that were never used AND double-count the goods' cost.
     *
     * LAUNCH-P3 P3-7: the same holds for an untracked product — only a
     * made-to-order ('ingredient') product deducts its recipe at sale.
     *
     * @return list<array{ingredient_id: int, qty: float, unit: string|null, unit_cost: float}>|null
     */
    public function productRecipe(?Product $product): ?array
    {
        if ($product === null || (string) $product->stock_mode !== 'ingredient') {
            return null;
        }

        $lines = $this->recipes->lines((int) $product->id);
        if ($lines === []) {
            return null;
        }

        return array_map(static fn (array $line): array => [
            'ingredient_id' => $line['ingredient_id'],
            'qty' => (float) $line['quantity'],
            'unit' => $line['unit'],
            'unit_cost' => (float) $line['unit_cost'],
        ], $this->exploder->explode($lines));
    }

    /**
     * An add-on option's stock-use copy:
     *  - its PD3b lines (consumption_snapshot_json) when it has any, prep
     *    ingredient lines exploded and merged per raw ingredient + direction;
     *  - else the legacy single-ingredient trio (ingredient_snapshot_json). A
     *    trio naming a prep item cannot hold several raw ingredients, so it
     *    is copied as its exploded 'add' lines instead (the same deduction:
     *    an 'add' line is add-on consumption on top of the recipe).
     *
     * @return array{ingredient_snapshot_json: array<string, mixed>|null, consumption_snapshot_json: list<array<string, mixed>>|null}
     */
    public function addonStockUse(AddOn $addOn): array
    {
        $rows = DB::table('pos_addon_consumptions')
            ->where('add_on_id', (int) $addOn->id)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        if ($rows->isNotEmpty()) {
            return ['ingredient_snapshot_json' => null, 'consumption_snapshot_json' => $this->consumptionLines($rows)];
        }

        if ($addOn->ingredient_id === null) {
            return ['ingredient_snapshot_json' => null, 'consumption_snapshot_json' => null];
        }

        $ingredientId = (int) $addOn->ingredient_id;
        if ($this->exploder->isPrep($ingredientId)) {
            $lines = $this->consumptionLines([(object) [
                'ingredient_id' => $ingredientId,
                'component_product_id' => null,
                'direction' => 'add',
                'quantity' => $addOn->ingredient_qty,
                'unit' => null,
            ]]);

            return ['ingredient_snapshot_json' => null, 'consumption_snapshot_json' => $lines === [] ? null : $lines];
        }

        return [
            'ingredient_snapshot_json' => [
                'ingredient_id' => $ingredientId,
                'qty' => (float) $addOn->ingredient_qty,
                'unit' => $addOn->ingredient_unit,
                'unit_cost' => (float) StockDecimal::exact($this->exploder->ingredient($ingredientId)?->default_unit_cost ?? 0),
            ],
            'consumption_snapshot_json' => null,
        ];
    }

    /**
     * PD3b option lines (ingredient XOR product, add|remove, per ONE parent
     * unit) with prep ingredient lines exploded. Raw ingredient lines merge
     * per (ingredient, direction), exact until the end; everything keeps the
     * option's own line order. An option without prep lines copies exactly
     * as before.
     *
     * @param  iterable<object>  $rows  pos_addon_consumptions rows, in display order
     * @return list<array<string, mixed>>
     */
    public function consumptionLines(iterable $rows): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);

        $ingredientLines = [];
        $ingredientIndexByRow = [];
        foreach ($rows as $index => $row) {
            if ($row->ingredient_id !== null) {
                $ingredientIndexByRow[$index] = count($ingredientLines);
                $ingredientLines[] = [
                    'ingredient_id' => (int) $row->ingredient_id,
                    'quantity' => $row->quantity,
                    'unit' => $row->unit,
                    'group' => (string) $row->direction,
                ];
            }
        }

        $partsByLine = [];
        foreach ($this->exploder->explode($ingredientLines) as $part) {
            $partsByLine[$part['line']][] = $part;
        }

        $out = [];
        foreach ($rows as $index => $row) {
            if ($row->ingredient_id === null) {
                $out[] = [
                    'type' => 'product',
                    'product_id' => (int) $row->component_product_id,
                    'direction' => (string) $row->direction,
                    'qty' => (float) $row->quantity,
                ];

                continue;
            }

            foreach ($partsByLine[$ingredientIndexByRow[$index]] ?? [] as $part) {
                $out[] = [
                    'type' => 'ingredient',
                    'ingredient_id' => $part['ingredient_id'],
                    'direction' => $part['group'],
                    'qty' => (float) $part['quantity'],
                    'unit' => $part['unit'],
                    'unit_cost' => (float) $part['unit_cost'],
                ];
            }
        }

        return $out;
    }

    public function exploder(): PrepExploder
    {
        return $this->exploder;
    }
}
