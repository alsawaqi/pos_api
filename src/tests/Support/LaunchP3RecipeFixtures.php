<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LAUNCH-P3 fixtures — company 100, branch 10, base units g / ml / piece.
 *
 *   Garlic sauce (prep, makes 1000 ml): garlic 200 g, oil 700 ml, lemon 100 ml
 *     → cost per ml = (200×0.002 + 700×0.0015 + 100×0.001) ÷ 1000 = 0.00155
 *   Shawarma (made-to-order): chicken 150 g, bread 1 piece, sauce 30 ml, garlic 5 g
 *     → raw per unit: chicken 150, bread 1, garlic 5 + 6 = 11, oil 21, lemon 3
 *     → cost per unit 0.7065
 *   Platter (cooked): chicken 200 g, sauce 50 ml
 *     → raw per piece: chicken 200, garlic 10, oil 35, lemon 5; cost 0.8775
 */
trait LaunchP3RecipeFixtures
{
    protected const GARLIC = 1;

    protected const OIL = 2;

    protected const LEMON = 3;

    protected const SAUCE = 4;

    protected const CHICKEN = 5;

    protected const BREAD = 6;

    protected const SHAWARMA = 1;

    protected const PLATTER = 2;

    protected function seedPrepKitchen(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(self::GARLIC, 'Garlic', 'g', '0.002000'),
            $this->ingredientRow(self::OIL, 'Oil', 'ml', '0.001500'),
            $this->ingredientRow(self::LEMON, 'Lemon juice', 'ml', '0.001000'),
            // A prep item carries no meaningful cost or stock of its own.
            $this->ingredientRow(self::SAUCE, 'Garlic sauce', 'ml', '0', isPrep: true, yield: '1000'),
            $this->ingredientRow(self::CHICKEN, 'Chicken', 'g', '0.004000'),
            $this->ingredientRow(self::BREAD, 'Bread', 'piece', '0.050000'),
        ]);
        $this->prepRecipe(self::SAUCE, [[self::GARLIC, '200'], [self::OIL, '700'], [self::LEMON, '100']]);

        DB::table('pos_products')->insert([
            ['id' => self::SHAWARMA, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Shawarma', 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
            ['id' => self::PLATTER, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Platter', 'base_price' => 3.000, 'status' => 'active', 'stock_mode' => 'cooked'] + $t,
        ]);
        $this->productRecipe(self::SHAWARMA, [
            [self::CHICKEN, '150', 'g'],
            [self::BREAD, '1', 'piece'],
            [self::SAUCE, '30', 'ml'],
            [self::GARLIC, '5', 'g'],
        ]);
        $this->productRecipe(self::PLATTER, [
            [self::CHICKEN, '200', 'g'],
            [self::SAUCE, '50', 'ml'],
        ]);

        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::GARLIC, 'quantity' => '1000'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::OIL, 'quantity' => '1000'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::LEMON, 'quantity' => '1000'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::CHICKEN, 'quantity' => '5000'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::BREAD, 'quantity' => '50'] + $t,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ingredientRow(int $id, string $name, string $unit, string $cost, bool $isPrep = false, ?string $yield = null, int $companyId = 100): array
    {
        return [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'name' => $name,
            'unit' => $unit,
            'default_unit_cost' => $cost,
            'status' => 'active',
            'is_prep' => $isPrep,
            'prep_yield_quantity' => $yield,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  list<array{0: int, 1: string}>  $components  [ingredient id, quantity per batch]
     */
    protected function prepRecipe(int $prepId, array $components): void
    {
        foreach ($components as $index => [$ingredientId, $quantity]) {
            DB::table('pos_ingredient_recipes')->insert([
                'prep_ingredient_id' => $prepId,
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $lines  [ingredient id, quantity, unit]
     */
    protected function productRecipe(int $productId, array $lines): void
    {
        foreach ($lines as $index => [$ingredientId, $quantity, $unit]) {
            DB::table('pos_product_recipes')->insert([
                'product_id' => $productId,
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'unit_at_set' => $unit,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function branchBalance(int $ingredientId, int $branchId = 10): ?float
    {
        $value = DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->value('quantity');

        return $value === null ? null : (float) $value;
    }

    /**
     * A prep item never gets a stock row or a stock movement.
     */
    protected function assertPrepHasNoStock(int $prepId = self::SAUCE): void
    {
        $this->assertFalse(DB::table('pos_branch_stock')->where('ingredient_id', $prepId)->exists(), 'a prep item got a branch stock row');
        $this->assertFalse(DB::table('pos_stock_movements')->where('ingredient_id', $prepId)->exists(), 'a prep item got a stock movement');
    }
}
