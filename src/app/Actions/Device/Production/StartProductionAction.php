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
 *   - every required ingredient's branch balance is checked against FRESH
 *     locked rows (production is online-only precisely for this) and the
 *     batch is refused if anything falls short — unlike sales, production
 *     does NOT run negative;
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

        $ingredientIds = array_values(array_unique(array_merge(
            $recipeRows->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all(),
            array_keys($extraByIngredient),
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

        return DB::transaction(function () use ($device, $product, $quantity, $staffId, $recipeRows, $extraByIngredient, $ingredientIds, $ingredients, $companyId, $branchId): Production {
            // Total needed per ingredient (std + extra) for the coverage check.
            $needed = [];
            foreach ($recipeRows as $line) {
                $needed[(int) $line->ingredient_id] = ($needed[(int) $line->ingredient_id] ?? 0.0)
                    + ((float) $line->quantity * $quantity);
            }
            foreach ($extraByIngredient as $ingredientId => $extraQty) {
                $needed[$ingredientId] = ($needed[$ingredientId] ?? 0.0) + $extraQty;
            }

            // Lock the balance rows in a deterministic order, then verify
            // coverage against the FRESH values. Missing row = balance 0.
            sort($ingredientIds);
            $balances = [];
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

                    throw new RuntimeException(sprintf(
                        'Not enough %s: need %s %s, have %s.',
                        $ingredient->name,
                        rtrim(rtrim(number_format($needed[$ingredientId], 3, '.', ''), '0'), '.'),
                        $ingredient->unit,
                        rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                    ));
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

                ProductionLine::create([
                    'production_id' => $production->id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => number_format($qty, 3, '.', ''),
                    'unit_at_time' => $unitAtTime ?? (string) $ingredient->unit,
                    'is_extra' => $isExtra,
                ]);

                StockMovement::create([
                    'branch_id' => $branchId,
                    'ingredient_id' => $ingredientId,
                    'movement_type' => StockMovement::TYPE_PRODUCTION_CONSUMPTION,
                    'quantity' => number_format(-$qty, 3, '.', ''),
                    'unit_cost_at_time' => number_format((float) ($ingredient->default_unit_cost ?? 0), 3, '.', ''),
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

            foreach ($recipeRows as $line) {
                $consume(
                    (int) $line->ingredient_id,
                    (float) $line->quantity * $quantity,
                    false,
                    (string) $line->unit_at_set,
                );
            }
            foreach ($extraByIngredient as $ingredientId => $extraQty) {
                $consume($ingredientId, $extraQty, true, null);
            }

            return $production;
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
