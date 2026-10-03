<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P3 fix order 1, Part B — device product waste.
 *
 *  K2: a cooked piece is wasted at the batch cost per piece stamped on the
 *      branch's latest 'produced' movement (what its cost of goods reads),
 *      with the old rule (cost price, else recipe cost) as the fallback.
 *  K3: waste follows the selling rule (owner decision 2026-10-02): it is
 *      never refused for a short shelf; the result warns instead.
 *
 * Cake (cooked): flour 0.5 kg @ 0.300 + sugar 0.2 kg @ 0.500 = 0.250 a piece;
 * cheese @ 4.000 / kg for a declared extra. Cola (unit): cost price 0.200.
 */
class LaunchP3FixWasteTest extends TestCase
{
    use RefreshDatabase;

    private const CAKE = 1;

    private const COLA = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            ['id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake', 'base_price' => 5.000, 'cost_price' => null, 'status' => 'active', 'stock_mode' => 'cooked'] + $t,
            ['id' => self::COLA, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cola', 'base_price' => 0.500, 'cost_price' => '0.200', 'status' => 'active', 'stock_mode' => 'unit'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour', 'unit' => 'kg', 'default_unit_cost' => '0.300000', 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sugar', 'unit' => 'kg', 'default_unit_cost' => '0.500000', 'status' => 'active'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cheese', 'unit' => 'kg', 'default_unit_cost' => '4.000000', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::CAKE, 'ingredient_id' => 1, 'quantity' => '0.5', 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
            ['product_id' => self::CAKE, 'ingredient_id' => 2, 'quantity' => '0.2', 'unit_at_set' => 'kg', 'sort_order' => 2] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => '50'] + $t,
            ['branch_id' => 10, 'ingredient_id' => 2, 'quantity' => '40'] + $t,
            ['branch_id' => 10, 'ingredient_id' => 3, 'quantity' => '5'] + $t,
        ]);
        Device::factory()->paired('mdev_k23')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: int|float}>  $extras
     */
    private function batch(int $pieces, array $extras = []): void
    {
        $uuid = (string) $this->withToken('mdev_k23')->postJson('/api/v1/device/productions', [
            'product_id' => self::CAKE, 'quantity' => $pieces, 'staff_id' => 7, 'extras' => $extras,
        ])->assertCreated()->json('data.production.uuid');
        $this->withToken('mdev_k23')->postJson("/api/v1/device/productions/{$uuid}/finish", ['staff_id' => 7])->assertOk();
    }

    private function waste(int $productId, float $qty): TestResponse
    {
        return $this->withToken('mdev_k23')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'product.waste',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['lines' => [['product_id' => $productId, 'qty' => $qty, 'reason' => 'spoiled']], 'staff_id' => 7],
        ]]])->assertOk();
    }

    private function wasteCost(int $productId): float
    {
        return (float) DB::table('pos_product_stock_movements')->where('product_id', $productId)
            ->where('movement_type', 'waste')->orderByDesc('id')->value('unit_cost');
    }

    private function shelf(int $productId): ?float
    {
        $value = DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', $productId)->value('stock_qty');

        return $value === null ? null : (float) $value;
    }

    // ---- K2 -------------------------------------------------------------------

    public function test_k2_a_cooked_piece_is_wasted_at_its_batch_cost_with_extras(): void
    {
        // 10 pieces + 1 kg extra cheese: (10 × 0.250 + 4.000) ÷ 10 = 0.650 a piece.
        $this->batch(10, [['ingredient_id' => 3, 'quantity' => 1]]);
        $this->assertSame('processed', $this->waste(self::CAKE, 1)->json('data.results.0.status'));

        $this->assertSame(0.65, (float) DB::table('pos_product_stock_movements')->where('movement_type', 'produced')->value('unit_cost'));
        $this->assertSame(0.65, $this->wasteCost(self::CAKE));
    }

    public function test_k2_a_later_average_cost_does_not_revalue_the_wasted_piece(): void
    {
        $this->batch(2);
        DB::table('pos_ingredients')->where('id', 1)->update(['default_unit_cost' => '0.900000']);
        $this->waste(self::CAKE, 1);

        $this->assertSame(0.25, $this->wasteCost(self::CAKE));
    }

    public function test_k2_the_batch_cost_wins_over_a_cost_price_and_the_old_rule_is_the_fallback(): void
    {
        DB::table('pos_products')->where('id', self::CAKE)->update(['cost_price' => '0.100']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => self::CAKE, 'is_available' => true, 'stock_qty' => 3, 'created_at' => now(), 'updated_at' => now()]);

        // No stamped batch yet: the cost price, as before.
        $this->waste(self::CAKE, 1);
        $this->assertSame(0.1, $this->wasteCost(self::CAKE));

        $this->batch(2);
        $this->waste(self::CAKE, 1);
        $this->assertSame(0.25, $this->wasteCost(self::CAKE));

        // A bought-in product keeps its cost price.
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => self::COLA, 'is_available' => true, 'stock_qty' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->waste(self::COLA, 1);
        $this->assertSame(0.2, $this->wasteCost(self::COLA));
    }

    // ---- K3 -------------------------------------------------------------------

    public function test_k3_waste_beyond_the_shelf_is_recorded_and_warned_not_refused(): void
    {
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => self::COLA, 'is_available' => true, 'stock_qty' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->waste(self::COLA, 5)->json('data.results.0');

        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame([['product_id' => self::COLA, 'name' => 'Cola', 'wasted' => '5.000', 'on_shelf' => '2.000']], $result['result']['shelf_shortfalls']);
        $this->assertSame(-3.0, $this->shelf(self::COLA));
        $this->assertDatabaseHas('pos_product_stock_movements', ['product_id' => self::COLA, 'movement_type' => 'waste', 'quantity' => '-5.000']);
    }

    public function test_k3_waste_with_no_shelf_count_at_the_branch_is_recorded_without_creating_one(): void
    {
        $result = $this->waste(self::CAKE, 2)->json('data.results.0');

        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame([['product_id' => self::CAKE, 'name' => 'Cake', 'wasted' => '2.000', 'on_shelf' => null]], $result['result']['shelf_shortfalls']);
        $this->assertDatabaseHas('pos_product_stock_movements', ['product_id' => self::CAKE, 'movement_type' => 'waste', 'quantity' => '-2.000']);
        // No row is created: a pos_branch_product row would also restrict where the cake is sold.
        $this->assertFalse(DB::table('pos_branch_product')->where('product_id', self::CAKE)->exists());
    }

    public function test_k3_a_covered_waste_carries_no_warning(): void
    {
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => self::COLA, 'is_available' => true, 'stock_qty' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->waste(self::COLA, 5)->json('data.results.0');

        $this->assertSame('processed', $result['status']);
        $this->assertArrayNotHasKey('shelf_shortfalls', $result['result']);
        $this->assertSame(0.0, $this->shelf(self::COLA));
    }
}
