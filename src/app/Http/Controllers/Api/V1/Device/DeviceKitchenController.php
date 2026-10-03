<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Production;
use App\Models\ProductionLine;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Recipes\PrepExploder;
use App\Support\StockDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/device/kitchen — P-G1 the Kitchen production screen's data.
 *
 * ONLINE-ONLY by design (production validates against fresh balances, so
 * the screen shows fresh numbers too). Returns, for the device's branch:
 *
 *   products    every COOKED product available at this branch, with its
 *               recipe (ingredient names + per-piece amounts), the live
 *               branch ingredient balances, the current shelf count, and
 *               the server-computed "can make up to N" (min over recipe
 *               lines of balance/per-piece, floored; null = no recipe =
 *               unconstrained).
 *   ingredients the company's active ingredients + branch balances, for
 *               the declared-extras picker.
 *   active      this branch's in-progress batches (resume / finish /
 *               cancel after an app restart or from another till).
 *
 * Who may OPEN the screen is the merchant's kitchen_positions setting,
 * enforced device-side from /device/config — the reports-screen precedent.
 */
class DeviceKitchenController
{
    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('stock_mode', 'cooked')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        // Branch availability: no rows = everywhere; otherwise need an
        // is_available row for this branch (the device-config rule).
        $branchRows = DB::table('pos_branch_product')
            ->whereIn('product_id', $products->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('product_id');

        // LAUNCH-P4 — the branch-scope rule (stock rows never restrict).
        $products = $products->filter(function (Product $p) use ($branchRows, $branchId): bool {
            return BranchCatalogue::availableAt($p, $branchRows->get($p->id)?->firstWhere('branch_id', $branchId));
        })->values();

        $recipesByProduct = DB::table('pos_product_recipes')
            ->whereIn('product_id', $products->pluck('id')->all() ?: [0])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $balances = DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->pluck('quantity', 'ingredient_id');

        // LAUNCH-P3 P3-4 — a batch deducts the raw ingredients behind a prep
        // item, so the screen lists those (per piece), and "can make up to N"
        // is computed over them; a prep item has no balance of its own.
        $exploder = new PrepExploder($companyId);
        $exploder->preload($recipesByProduct->flatten(1)->pluck('ingredient_id')->all());

        $mapProduct = function (Product $p) use ($recipesByProduct, $branchRows, $branchId, $balances, $exploder): array {
            // Fix order 1 L6 — per-piece amounts stay exact: the screen gets
            // them at 8 decimals (the till's "N × quantity" check then matches
            // what the batch deducts, recipe × N rounded once), and "can make
            // up to N" divides each balance by the EXACT per-piece amount.
            $recipeRows = $exploder->explodeRational(($recipesByProduct->get($p->id) ?? collect())->map(static fn (object $r): array => [
                'ingredient_id' => (int) $r->ingredient_id,
                'quantity' => $r->quantity,
                'unit' => $r->unit_at_set,
            ])->all());

            $max = null;
            $recipe = [];
            foreach ($recipeRows as $r) {
                /** @var BigRational $exact */
                $exact = $r['quantity'];
                $rawBalance = $balances[$r['ingredient_id']] ?? 0;
                $balance = (float) $rawBalance;
                $ingredient = $exploder->ingredient($r['ingredient_id']);

                $recipe[] = [
                    'ingredient_id' => $r['ingredient_id'],
                    'name' => $ingredient?->name ?? ('#'.$r['ingredient_id']),
                    'name_ar' => $ingredient?->name_ar,
                    'quantity' => (float) (string) $exact->toScale(PrepExploder::PER_UNIT_SCALE, RoundingMode::HALF_UP),
                    'unit' => $r['unit'],
                    'branch_balance' => $balance,
                ];

                if ($exact->isPositive()) {
                    $canMake = BigRational::of(StockDecimal::exact($rawBalance))->dividedBy($exact)
                        ->toScale(0, RoundingMode::FLOOR)->toInt();
                    $max = $max === null ? $canMake : min($max, $canMake);
                }
            }

            $mine = $branchRows->get($p->id)?->firstWhere('branch_id', $branchId);

            return [
                'id' => (int) $p->id,
                'uuid' => $p->uuid,
                'name' => $p->name,
                'name_ar' => $p->name_ar,
                'category_id' => $p->category_id !== null ? (int) $p->category_id : null,
                'image_url' => $p->image_url,
                // Current shelf count at this branch (null = nothing
                // produced/allocated yet -> the device shows it sold out).
                'branch_stock_qty' => $mine?->stock_qty !== null ? (float) $mine->stock_qty : null,
                // "Can make up to N" from live balances; null = no recipe.
                'max_producible' => $max !== null ? max(0, $max) : null,
                // P-G1.5 — default shelf life (prefills the Finish dialog's
                // batch expiry; null = keeps indefinitely).
                'shelf_life_days' => $p->shelf_life_days !== null ? (int) $p->shelf_life_days : null,
                'recipe' => $recipe,
            ];
        };

        $active = Production::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', Production::STATUS_IN_PROGRESS)
            ->with(['lines', 'startedByStaff:id,name', 'product:id,name,name_ar'])
            ->orderBy('started_at')
            ->get();

        return response()->json([
            'data' => [
                'products' => $products->map($mapProduct)->all(),
                // The extras picker. A prep item may be declared as an extra
                // (the batch then deducts its raw ingredients); it has no
                // balance of its own. is_prep is additive: older apps ignore it.
                'ingredients' => $ingredients->map(fn (Ingredient $i): array => [
                    'id' => (int) $i->id,
                    'name' => $i->name,
                    'name_ar' => $i->name_ar,
                    'unit' => $i->unit,
                    'branch_balance' => (float) ($balances[$i->id] ?? 0),
                    'is_prep' => (bool) ($i->is_prep ?? false),
                ])->all(),
                'active' => $active->map(fn (Production $p): array => $this->mapProduction($p))->all(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * The wire shape shared with DeviceProductionsController responses.
     *
     * @return array<string, mixed>
     */
    public static function productionPayload(Production $production): array
    {
        $production->loadMissing(['lines.ingredient:id,name,name_ar', 'startedByStaff:id,name', 'product:id,name,name_ar']);

        return [
            'uuid' => $production->uuid,
            'status' => $production->status,
            'product_id' => (int) $production->product_id,
            'product_name' => $production->product?->name,
            'product_name_ar' => $production->product?->name_ar,
            'quantity' => (float) $production->quantity,
            'started_at' => $production->started_at?->toIso8601String(),
            'finished_at' => $production->finished_at?->toIso8601String(),
            // P-G1.5 — the chef's batch expiry (null = never expires).
            'expires_at' => $production->expires_at?->toIso8601String(),
            'cancelled_at' => $production->cancelled_at?->toIso8601String(),
            'duration_seconds' => $production->duration_seconds !== null ? (int) $production->duration_seconds : null,
            'started_by' => $production->startedByStaff?->name,
            'lines' => $production->lines->map(fn (ProductionLine $line): array => [
                'ingredient_id' => (int) $line->ingredient_id,
                'name' => $line->ingredient?->name,
                'name_ar' => $line->ingredient?->name_ar,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit_at_time,
                'is_extra' => (bool) $line->is_extra,
            ])->all(),
        ];
    }

    private function mapProduction(Production $production): array
    {
        return self::productionPayload($production);
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
