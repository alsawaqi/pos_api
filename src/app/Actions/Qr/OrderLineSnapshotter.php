<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Freezes the inventory facts a QR order needs at later pay/void time. */
final class OrderLineSnapshotter
{
    /**
     * @return array{recipe_snapshot_json: array<int, array<string, mixed>>|null, component_snapshot_json: array<int, array{product_id: int, qty: float}>}
     */
    public function product(Product $product): array
    {
        return [
            'recipe_snapshot_json' => $this->recipe($product),
            'component_snapshot_json' => $this->components((int) $product->id),
        ];
    }

    /**
     * @return array{ingredient_snapshot_json: array<string, mixed>|null, linked_product_id: int|null, product_snapshot_json: array<string, mixed>|null, consumption_snapshot_json: array<int, array<string, mixed>>|null}
     */
    public function addon(AddOn $addOn, int $companyId): array
    {
        $consumption = $this->addonConsumption($addOn);

        return [
            'ingredient_snapshot_json' => $consumption === null ? $this->addonIngredient($addOn) : null,
            'linked_product_id' => $addOn->linked_product_id !== null ? (int) $addOn->linked_product_id : null,
            'product_snapshot_json' => $this->addonProduct($addOn, $companyId),
            'consumption_snapshot_json' => $consumption,
        ];
    }

    /** @return list<array{ingredient_id: int, qty: float, unit: string, unit_cost: float}>|null */
    private function recipe(Product $product): ?array
    {
        if (in_array((string) $product->stock_mode, ['cooked', 'unit'], true)) {
            return null;
        }

        $rows = DB::table('pos_product_recipes')
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $costs = Ingredient::query()
            ->whereIn('id', $rows->pluck('ingredient_id')->all())
            ->pluck('default_unit_cost', 'id');

        return $rows->map(fn ($row): array => [
            'ingredient_id' => (int) $row->ingredient_id,
            'qty' => (float) $row->quantity,
            'unit' => (string) $row->unit_at_set,
            'unit_cost' => (float) ($costs[$row->ingredient_id] ?? 0),
        ])->all();
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
    private function addonProduct(AddOn $addOn, int $companyId): ?array
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
            'recipe' => (string) $product->stock_mode === 'ingredient' ? $this->recipe($product) : null,
            'components' => $this->components((int) $product->id) ?: null,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function addonConsumption(AddOn $addOn): ?array
    {
        $rows = DB::table('pos_addon_consumptions')
            ->where('add_on_id', $addOn->id)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $ingredientIds = $rows->pluck('ingredient_id')->filter()->all();
        $costs = $ingredientIds === []
            ? collect()
            : Ingredient::query()->whereIn('id', $ingredientIds)->pluck('default_unit_cost', 'id');

        return $rows->map(static function ($row) use ($costs): array {
            if ($row->ingredient_id !== null) {
                return [
                    'type' => 'ingredient',
                    'ingredient_id' => (int) $row->ingredient_id,
                    'direction' => (string) $row->direction,
                    'qty' => (float) $row->quantity,
                    'unit' => $row->unit,
                    'unit_cost' => (float) ($costs[$row->ingredient_id] ?? 0),
                ];
            }

            return [
                'type' => 'product',
                'product_id' => (int) $row->component_product_id,
                'direction' => (string) $row->direction,
                'qty' => (float) $row->quantity,
            ];
        })->all();
    }

    /** @return array{ingredient_id: int, qty: float, unit: string|null, unit_cost: float}|null */
    private function addonIngredient(AddOn $addOn): ?array
    {
        if ($addOn->ingredient_id === null) {
            return null;
        }

        $cost = Ingredient::query()->whereKey($addOn->ingredient_id)->value('default_unit_cost');

        return [
            'ingredient_id' => (int) $addOn->ingredient_id,
            'qty' => (float) $addOn->ingredient_qty,
            'unit' => $addOn->ingredient_unit,
            'unit_cost' => (float) ($cost ?? 0),
        ];
    }
}
