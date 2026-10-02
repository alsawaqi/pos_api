<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-5 — batch cost. Finishing a kitchen batch stamps its
 * 'produced' product movement with the cost per piece: what the ingredients
 * consumed at start cost (extras included) ÷ the pieces, 6 decimals. A
 * cancelled batch returns its ingredients at the cost they left with, not at
 * the average of the day it is cancelled.
 *
 * Cake (cooked): flour 0.5 kg at 0.300 + sugar 0.2 kg at 0.500 per piece.
 */
class LaunchP3BatchCostTest extends TestCase
{
    use RefreshDatabase;

    private const CAKE = 1;

    private const FLOUR = 1;

    private const SUGAR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            'id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake',
            'base_price' => 5.000, 'status' => 'active', 'stock_mode' => 'cooked',
        ] + $t);
        DB::table('pos_ingredients')->insert([
            ['id' => self::FLOUR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour', 'unit' => 'kg', 'default_unit_cost' => '0.300000', 'status' => 'active'] + $t,
            ['id' => self::SUGAR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sugar', 'unit' => 'kg', 'default_unit_cost' => '0.500000', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::CAKE, 'ingredient_id' => self::FLOUR, 'quantity' => '0.5', 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
            ['product_id' => self::CAKE, 'ingredient_id' => self::SUGAR, 'quantity' => '0.2', 'unit_at_set' => 'kg', 'sort_order' => 2] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::FLOUR, 'quantity' => '5'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::SUGAR, 'quantity' => '4'] + $t,
        ]);
        DB::table('pos_staff')->insert([
            ['id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Chef', 'pin_hash' => Hash::make('1111'), 'position' => 'kitchen', 'status' => 'active'] + $t,
            ['id' => 8, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active'] + $t,
        ]);
        Device::factory()->paired('mdev_batch')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int}>  $extras
     */
    private function start(int $pieces, array $extras = []): string
    {
        return (string) $this->withToken('mdev_batch')->postJson('/api/v1/device/productions', [
            'product_id' => self::CAKE, 'quantity' => $pieces, 'staff_id' => 7, 'extras' => $extras,
        ])->assertCreated()->json('data.production.uuid');
    }

    public function test_a_finished_batch_carries_its_cost_per_piece_extras_included(): void
    {
        // 3 pieces: 3 × (0.5 × 0.300 + 0.2 × 0.500) = 0.750, plus an extra
        // 0.1 kg of sugar = 0.050 → 0.800 ÷ 3 = 0.266667 a piece.
        $uuid = $this->start(3, [['ingredient_id' => self::SUGAR, 'quantity' => 0.1]]);

        $this->withToken('mdev_batch')->postJson("/api/v1/device/productions/{$uuid}/finish", ['staff_id' => 7])->assertOk();

        $produced = DB::table('pos_product_stock_movements')->where('movement_type', 'produced')->sole();
        $this->assertSame('3.000', number_format((float) $produced->quantity, 3, '.', ''));
        $this->assertSame(0.266667, (float) $produced->unit_cost);
    }

    public function test_the_batch_cost_is_what_the_ingredients_cost_when_they_left(): void
    {
        $uuid = $this->start(2);
        // The average moves (goods received) before the chef finishes.
        DB::table('pos_ingredients')->where('id', self::FLOUR)->update(['default_unit_cost' => '0.900000']);

        $this->withToken('mdev_batch')->postJson("/api/v1/device/productions/{$uuid}/finish", ['staff_id' => 7])->assertOk();

        // 2 × 0.250 = 0.500 ÷ 2 = 0.250 a piece (not today's 0.550).
        $this->assertSame(0.25, (float) DB::table('pos_product_stock_movements')->where('movement_type', 'produced')->value('unit_cost'));
    }

    public function test_a_cancelled_batch_returns_ingredients_at_the_cost_they_left_with(): void
    {
        $uuid = $this->start(2, [['ingredient_id' => self::FLOUR, 'quantity' => 0.25]]);
        DB::table('pos_ingredients')->where('id', self::FLOUR)->update(['default_unit_cost' => '0.450000']);
        DB::table('pos_ingredients')->where('id', self::SUGAR)->update(['default_unit_cost' => '0.123456']);

        $this->withToken('mdev_batch')->postJson("/api/v1/device/productions/{$uuid}/cancel", ['pin' => '4321', 'staff_id' => 7])->assertOk();

        $returns = DB::table('pos_stock_movements')->where('movement_type', 'production_return')->orderBy('id')->get();
        $this->assertCount(3, $returns); // flour std + flour extra + sugar
        foreach ($returns as $return) {
            $leftWith = $return->ingredient_id == self::FLOUR ? 0.3 : 0.5;
            $this->assertSame($leftWith, (float) $return->unit_cost_at_time, 'ingredient '.$return->ingredient_id);
        }
        // The ledger nets to zero and the balances are whole again.
        $this->assertSame(5.0, (float) DB::table('pos_branch_stock')->where('ingredient_id', self::FLOUR)->value('quantity'));
        $this->assertSame(0.0, (float) DB::table('pos_stock_movements')->where('ingredient_id', self::FLOUR)->sum('quantity'));
    }
}
