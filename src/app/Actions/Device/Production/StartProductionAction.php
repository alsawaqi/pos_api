<?php

declare(strict_types=1);

namespace App\Actions\Device\Production;

use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\Production;
use App\Models\ProductionLine;
use App\Models\StockMovement;
use App\Support\Recipes\PrepExploder;
use App\Support\StockDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P-G1 — START a kitchen production batch (phase 1 of 2).
 *
 * The chef picked a cooked product + a piece quantity. The recipe amounts
 * are LOCKED (quantity x recipe — the device cannot tamper with them);
 * anything beyond the recipe arrives as explicit extra lines. On success:
 *
 *   - every required ingredient's branch balance is read from FRESH locked
 *     rows (production is online-only precisely for this). LAUNCH-P2 (owner
 *     decision 2026-10-02): like a sale, a batch is never refused on the
 *     stock numbers — a shortfall is reported back as a warning
 *     ({@see start()}) and the balance may go below zero, where the
 *     merchant portal flags it;
 *   - the ingredients are deducted immediately (they physically left the
 *     shelf; a parallel batch cannot claim them): one signed
 *     'production_consumption' pos_stock_movements row per line + the
 *     pos_branch_stock balance move, atomically;
 *   - a pos_productions row (status in_progress, started_at stamped) +
 *     its pos_production_lines (std locked rows, then declared extras).
 *
 * Throws RuntimeException with a user-facing message on any guard failure
 * (the controller maps it to 422).
 */
final readonly class StartProductionAction
{
    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>  $extras
     */
    public function handle(Device $device, int $productId, int $quantity, ?int $staffId, array $extras): Production
    {
        return $this->start($device, $productId, $quantity, $staffId, $extras)['production'];
    }

    /**
     * Start the batch and report what the books could not cover.
     *
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>  $extras
     * @return array{production: Production, shortfalls: list<array{ingredient_id: int, name: string, unit: string, needed: string, available: string}>}
     */
    public function start(Device $device, int $productId, int $quantity, ?int $staffId, array $extras): array
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        $product = Product::query()
            ->where('company_id', $companyId)
            ->find($productId);
        if ($product === null) {
            throw new RuntimeException('Unknown product.');
        }
        if ($product->stock_mode !== 'cooked') {
            throw new RuntimeException('Only cooked products can be produced in the kitchen.');
        }

        $this->assertAvailableAtBranch($productId, $branchId);

        if ($staffId !== null) {
            $staffOk = PosStaff::query()
                ->where('company_id', $companyId)
                ->where('status', PosStaff::STATUS_ACTIVE)
                ->whereKey($staffId)
                ->exists();
            if (! $staffOk) {
                throw new RuntimeException('Unknown staff member.');
            }
        }

        $recipeRows = DB::table('pos_product_recipes')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get();

        // Std lines: recipe x quantity, locked. Extra lines: merged per
        // ingredient (the dialog may add the same one twice).
        $extraByIngredient = [];
        foreach ($extras as $extra) {
            $ingredientId = (int) $extra['ingredient_id'];
            $extraQty = (float) $extra['quantity'];
            if ($extraQty <= 0) {
                continue;
            }
            $extraByIngredient[$ingredientId] = ($extraByIngredient[$ingredientId] ?? 0.0) + $extraQty;
        }

        // The extras the device named must be this company's (a prep item
        // included) before anything is exploded.
        $named = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($extraByIngredient) ?: [0])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (array_diff(array_keys($extraByIngredient), $named) !== []) {
            throw new RuntimeException('Unknown ingredient.');
        }

        // LAUNCH-P3 P3-4 — prep items (in the recipe or declared as extras)
        // explode into their raw ingredients: the batch deducts those, exact
        // until the end (recipe x pieces, then 4 decimals), and a prep item
        // never gets a stock row. Std and extra lines stay apart.
        $exploder = new PrepExploder($companyId);
        $stdLines = $exploder->explode($recipeRows->map(static fn (object $row): array => [
            'ingredient_id' => (int) $row->ingredient_id,
            'quantity' => $row->quantity,
            'unit' => $row->unit_at_set,
        ])->all(), $quantity);
        $extraLines = $exploder->explode(array_map(
            static fn (int $ingredientId, float $extraQty): array => [
                'ingredient_id' => $ingredientId,
                'quantity' => StockDecimal::exact($extraQty),
            ],
            array_keys($extraByIngredient),
            array_values($extraByIngredient),
        ));

        $ingredientIds = array_values(array_unique(array_merge(
            array_column($stdLines, 'ingredient_id'),
            array_column($extraLines, 'ingredient_id'),
        )));

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ingredientIds ?: [0])
            ->get()
            ->keyBy('id');

        foreach ($ingredientIds as $ingredientId) {
            if (! $ingredients->has($ingredientId)) {
                throw new RuntimeException('Unknown ingredient.');
            }
        }

        return DB::transaction(function () use ($device, $product, $quantity, $staffId, $stdLines, $extraLines, $ingredientIds, $ingredients, $companyId, $branchId): array {
            // Total needed per ingredient (std + extra) for the coverage check.
            $needed = [];
            foreach ([...$stdLines, ...$extraLines] as $line) {
                $needed[$line['ingredient_id']] = ($needed[$line['ingredient_id']] ?? 0.0) + (float) $line['quantity'];
            }

            // Lock the balance rows in a deterministic order and read the
            // FRESH values. Missing row = balance 0. A shortfall is a
            // warning, never a refusal (LAUNCH-P2 "sell, but warn").
            sort($ingredientIds);
            $balances = [];
            $shortfalls = [];
            foreach ($ingredientIds as $ingredientId) {
                $row = BranchStock::query()
                    ->where('branch_id', $branchId)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first();
                $balances[$ingredientId] = $row;

                $available = $row !== null ? (float) $row->quantity : 0.0;
                if ($available + 1e-9 < $needed[$ingredientId]) {
                    $ingredient = $ingredients->get($ingredientId);
                    $shortfalls[] = [
                        'ingredient_id' => (int) $ingredientId,
                        'name' => (string) $ingredient->name,
                        'unit' => (string) $ingredient->unit,
                        'needed' => StockDecimal::quantity($needed[$ingredientId]),
                        'available' => StockDecimal::quantity($available),
                    ];
                }
            }

            $production = Production::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'device_id' => $device->id,
                'quantity' => number_format($quantity, 3, '.', ''),
                'status' => Production::STATUS_IN_PROGRESS,
                'started_by_staff_id' => $staffId,
                'started_at' => now(),
            ]);

            $consume = function (int $ingredientId, float $qty, bool $isExtra, ?string $unitAtTime) use ($production, $balances, $ingredients, $branchId, $staffId): void {
                $ingredient = $ingredients->get($ingredientId);
                // LAUNCH-P2 — ledger precision (4dp) once: line, movement and
                // balance move by the same amount; the cost keeps 6 decimals.
                $qty = round($qty, StockDecimal::QUANTITY_SCALE);
                // Fix order 1 L6 — a line that rounds to nothing (a pinch of
                // saffron in a 1-piece batch) writes no junk 0 rows.
                if ($qty === 0.0) {
                    return;
                }

                ProductionLine::create([
                    'production_id' => $production->id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => StockDecimal::quantity($qty),
                    'unit_at_time' => $unitAtTime ?? (string) $ingredient->unit,
                    'is_extra' => $isExtra,
                ]);

                StockMovement::create([
                    'branch_id' => $branchId,
                    'ingredient_id' => $ingredientId,
                    'movement_type' => StockMovement::TYPE_PRODUCTION_CONSUMPTION,
                    'quantity' => StockDecimal::quantity(-$qty),
                    'unit_cost_at_time' => StockDecimal::unitCost($ingredient->default_unit_cost ?? 0),
                    'reference_type' => 'pos_productions',
                    'reference_id' => (int) $production->id,
                    'recorded_by_pos_staff_id' => $staffId,
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);

                $stock = $balances[$ingredientId] ?? BranchStock::firstOrNew([
                    'branch_id' => $branchId,
                    'ingredient_id' => $ingredientId,
                ]);
                $stock->quantity = (float) $stock->quantity - $qty;
                $stock->last_movement_at = now();
                $stock->save();
            };

            foreach ($stdLines as $line) {
                $consume($line['ingredient_id'], (float) $line['quantity'], false, $line['unit']);
            }
            foreach ($extraLines as $line) {
                // An extra keeps the ingredient's own unit, as before.
                $consume($line['ingredient_id'], (float) $line['quantity'], true, null);
            }

            return ['production' => $production, 'shortfalls' => $shortfalls];
        });
    }

    /**
     * Same availability rule the device config uses: a product with NO
     * pos_branch_product rows is available everywhere; otherwise it needs
     * a row for this branch with is_available = true.
     */
    private function assertAvailableAtBranch(int $productId, int $branchId): void
    {
        $rows = DB::table('pos_branch_product')
            ->where('product_id', $productId)
            ->get(['branch_id', 'is_available']);

        if ($rows->isEmpty()) {
            return;
        }

        $mine = $rows->firstWhere('branch_id', $branchId);
        if ($mine === null || ! (bool) $mine->is_available) {
            throw new RuntimeException('This product is not available at your branch.');
        }
    }
}
