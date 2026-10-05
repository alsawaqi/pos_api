<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\Product;
use App\Support\Recipes\RecipeCopy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the inventory facts a QR order needs at later pay/void time.
 *
 * LAUNCH-P3 — the recipe and option copies go through {@see RecipeCopy}:
 * prep items explode into raw ingredients (P3-4) and only a made-to-order
 * product copies a recipe (P3-7). QR orders and rounds are placed live, so
 * the recipe in force is the current one; a device staff round passes its
 * sale moment instead (P3-6, $recipeAt).
 */
final class OrderLineSnapshotter
{
    /**
     * LAUNCH review add-on — $removedIngredientIds: the recipe ingredients
     * the line's Remove options leave out ({@see removedIngredientIds()}).
     *
     * @param  list<int>  $removedIngredientIds
     * @return array{recipe_snapshot_json: array<int, array<string, mixed>>|null, component_snapshot_json: array<int, array{product_id: int, qty: float}>}
     */
    public function product(Product $product, ?CarbonInterface $recipeAt = null, array $removedIngredientIds = []): array
    {
        return [
            'recipe_snapshot_json' => $this->recipe($product, new RecipeCopy((int) $product->company_id, $recipeAt), $removedIngredientIds),
            'component_snapshot_json' => $this->components((int) $product->id),
        ];
    }

    /**
     * The recipe ingredients a line's chosen add-ons remove (company-scoped).
     *
     * @param  iterable<int|string>  $addOnIds
     * @return list<int>
     */
    public function removedIngredientIds(int $companyId, iterable $addOnIds): array
    {
        return (new RecipeCopy($companyId))->removedIngredientIds($addOnIds);
    }

    /**
     * @return array{ingredient_snapshot_json: array<string, mixed>|null, linked_product_id: int|null, product_snapshot_json: array<string, mixed>|null, consumption_snapshot_json: array<int, array<string, mixed>>|null}
     */
    public function addon(AddOn $addOn, int $companyId, ?CarbonInterface $recipeAt = null): array
    {
        $copy = new RecipeCopy($companyId, $recipeAt);
        $stockUse = $copy->addonStockUse($addOn);

        return [
            'ingredient_snapshot_json' => $stockUse['ingredient_snapshot_json'],
            'linked_product_id' => $addOn->linked_product_id !== null ? (int) $addOn->linked_product_id : null,
            'product_snapshot_json' => $this->addonProduct($addOn, $companyId, $copy),
            'consumption_snapshot_json' => $stockUse['consumption_snapshot_json'],
        ];
    }

    /**
     * @param  list<int>  $removedIngredientIds
     * @return list<array{ingredient_id: int, qty: float, unit: string, unit_cost: float}>|null
     */
    private function recipe(Product $product, RecipeCopy $copy, array $removedIngredientIds = []): ?array
    {
        $lines = $copy->productRecipe($product, $removedIngredientIds);

        return $lines === null ? null : array_map(static fn (array $line): array => [
            'ingredient_id' => $line['ingredient_id'],
            'qty' => $line['qty'],
            'unit' => (string) $line['unit'],
            'unit_cost' => $line['unit_cost'],
        ], $lines);
    }

    /** @return list<array{product_id: int, qty: float}> */
    private function components(int $productId): array
    {
        return DB::table('pos_product_components')
            ->where('product_id', $productId)
            ->get()
            ->map(static fn ($row): array => [
                'product_id' => (int) $row->component_product_id,
                'qty' => (float) $row->quantity,
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function addonProduct(AddOn $addOn, int $companyId, RecipeCopy $copy): ?array
    {
        if ($addOn->linked_product_id === null) {
            return null;
        }

        $product = Product::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->find((int) $addOn->linked_product_id);
        if ($product === null) {
            return null;
        }

        return [
            'product_id' => (int) $product->id,
            'stock_mode' => (string) $product->stock_mode,
            'recipe' => $this->recipe($product, $copy),
            'components' => $this->components((int) $product->id) ?: null,
        ];
    }
}
