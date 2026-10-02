<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-4 (device API, everything that evaluates a recipe): the device
 * config (recipe lines and low_stock), the kitchen screen (recipe lines and
 * "can make up to N"), a kitchen batch start, the product-waste cost — all
 * through the raw ingredients behind a prep item. A prep item has no stock:
 * it never reaches the device's ingredient list, and a stale count or restock
 * line for one is skipped instead of creating a stock row.
 */
class LaunchP3PrepItemsKitchenConfigTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $this->seedPrepKitchen();
        Device::factory()->paired('mdev_p3k')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->withToken('mdev_p3k')->getJson('/api/v1/device/config')->assertOk()->json('data');
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function byId(array $data, int $id): array
    {
        return collect($data)->firstWhere('id', $id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, float>
     */
    private function quantities(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line['ingredient_id']] = (float) $line['quantity'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushEvent(string $type, array $payload): TestResponse
    {
        return $this->withToken('mdev_p3k')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ]]])->assertOk();
    }

    public function test_the_device_config_sends_raw_recipe_lines_and_warns_on_the_sauce_ingredients(): void
    {
        $shawarma = $this->byId($this->config()['products'], self::SHAWARMA);

        $this->assertSame(
            [self::CHICKEN => 150.0, self::BREAD => 1.0, self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0],
            $this->quantities($shawarma['recipe']),
        );
        $this->assertSame(['ingredient_id', 'quantity', 'unit'], array_keys($shawarma['recipe'][0]));
        // Every raw ingredient covers a portion: no warning (the sauce itself
        // has no balance and must not count as short).
        $this->assertFalse($shawarma['low_stock']);

        // The oil behind the sauce runs short of one portion (21 ml).
        DB::table('pos_branch_stock')->where('ingredient_id', self::OIL)->update(['quantity' => '20']);
        $this->assertTrue($this->byId($this->config()['products'], self::SHAWARMA)['low_stock']);

        // ... or below its minimum.
        DB::table('pos_branch_stock')->where('ingredient_id', self::OIL)->update(['quantity' => '500']);
        DB::table('pos_ingredients')->where('id', self::LEMON)->update(['min_stock_threshold' => '2000']);
        $this->assertTrue($this->byId($this->config()['products'], self::SHAWARMA)['low_stock']);
    }

    public function test_prep_items_never_reach_the_device_ingredient_list(): void
    {
        $ids = array_column($this->config()['ingredients'], 'id');
        $this->assertContains(self::GARLIC, $ids);
        $this->assertNotContains(self::SAUCE, $ids);

        // An ingredient turned into a prep item after a device synced is
        // purged from that device's list on its next delta.
        DB::table('pos_ingredients')->insert($this->ingredientRow(7, 'Tahini', 'g', '0.003000'));
        $since = now()->subMinute();
        DB::table('pos_ingredients')->where('id', 7)->update(['is_prep' => true, 'prep_yield_quantity' => '500', 'updated_at' => now()]);

        $delta = $this->withToken('mdev_p3k')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertNotContains(7, array_column($delta['ingredients'], 'id'));
        $this->assertContains(7, $delta['deleted']['ingredients']);
    }

    public function test_the_device_config_explodes_add_on_option_lines(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_addon_groups')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sauces'] + $t);
        DB::table('pos_addons')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Extra sauce', 'price_delta' => 0.200, 'status' => 'active'] + $t);
        DB::table('pos_addon_consumptions')->insert([
            ['add_on_id' => 1, 'ingredient_id' => self::SAUCE, 'direction' => 'add', 'quantity' => '20', 'unit' => 'ml', 'display_order' => 0] + $t,
            ['add_on_id' => 1, 'ingredient_id' => self::GARLIC, 'direction' => 'add', 'quantity' => '1', 'unit' => 'g', 'display_order' => 1] + $t,
        ]);

        $addon = $this->byId($this->config()['addon_groups'], 1)['addons'][0];

        // JSON round-trips whole floats as ints — compare loosely.
        $this->assertEquals([
            ['type' => 'ingredient', 'ingredient_id' => self::GARLIC, 'product_id' => null, 'direction' => 'add', 'qty' => 5.0, 'unit' => 'g'],
            ['type' => 'ingredient', 'ingredient_id' => self::OIL, 'product_id' => null, 'direction' => 'add', 'qty' => 14.0, 'unit' => 'ml'],
            ['type' => 'ingredient', 'ingredient_id' => self::LEMON, 'product_id' => null, 'direction' => 'add', 'qty' => 2.0, 'unit' => 'ml'],
        ], $addon['consumption']);
    }

    public function test_the_kitchen_lists_raw_lines_and_can_make_from_them(): void
    {
        // 70 ml of oil makes two platters (35 ml of oil each, via the sauce).
        DB::table('pos_branch_stock')->where('ingredient_id', self::OIL)->update(['quantity' => '70']);

        $data = $this->withToken('mdev_p3k')->getJson('/api/v1/device/kitchen')->assertOk()->json('data');
        $platter = $this->byId($data['products'], self::PLATTER);

        $this->assertSame(
            [self::CHICKEN => 200.0, self::GARLIC => 10.0, self::OIL => 35.0, self::LEMON => 5.0],
            $this->quantities($platter['recipe']),
        );
        $this->assertSame('Oil', $platter['recipe'][2]['name']);
        $this->assertEqualsWithDelta(70.0, $platter['recipe'][2]['branch_balance'], 1e-9);
        $this->assertSame(2, $platter['max_producible']);

        // The extras picker offers the sauce (a batch explodes it), flagged.
        $sauce = $this->byId($data['ingredients'], self::SAUCE);
        $this->assertTrue($sauce['is_prep']);
        $this->assertFalse($this->byId($data['ingredients'], self::OIL)['is_prep']);
    }

    public function test_a_batch_deducts_the_raw_ingredients_of_a_prep_item_including_a_prep_extra(): void
    {
        $response = $this->withToken('mdev_p3k')->postJson('/api/v1/device/productions', [
            'product_id' => self::PLATTER,
            'quantity' => 3,
            'staff_id' => 7,
            'extras' => [['ingredient_id' => self::SAUCE, 'quantity' => 20]],
        ])->assertCreated();

        $lines = collect($response->json('data.production.lines'));
        $std = $lines->where('is_extra', false)->mapWithKeys(static fn (array $l): array => [$l['ingredient_id'] => (float) $l['quantity']])->all();
        $extra = $lines->where('is_extra', true)->mapWithKeys(static fn (array $l): array => [$l['ingredient_id'] => (float) $l['quantity']])->all();
        $this->assertSame([self::CHICKEN => 600.0, self::GARLIC => 30.0, self::OIL => 105.0, self::LEMON => 15.0], $std);
        $this->assertSame([self::GARLIC => 4.0, self::OIL => 14.0, self::LEMON => 2.0], $extra);
        $this->assertSame('ml', $lines->firstWhere('ingredient_id', self::OIL)['unit']);

        $this->assertSame(966.0, $this->branchBalance(self::GARLIC));
        $this->assertSame(881.0, $this->branchBalance(self::OIL));
        $this->assertSame(4400.0, $this->branchBalance(self::CHICKEN));
        $this->assertPrepHasNoStock();
        $this->assertFalse(DB::table('pos_production_lines')->where('ingredient_id', self::SAUCE)->exists());
    }

    public function test_a_count_or_restock_line_for_a_prep_item_is_skipped(): void
    {
        $count = $this->pushEvent('stock.count', ['staff_id' => 7, 'lines' => [
            ['ingredient_id' => self::GARLIC, 'counted_units' => 990],
            ['ingredient_id' => self::SAUCE, 'counted_units' => 500],
        ]]);
        $this->assertSame('processed', $count->json('data.results.0.status'), (string) json_encode($count->json('data.results.0')));
        $this->assertSame(1, $count->json('data.results.0.result.lines'));
        $this->assertSame([self::SAUCE], $count->json('data.results.0.result.skipped_prep_ingredient_ids'));
        $this->assertSame([self::GARLIC], DB::table('pos_stock_count_lines')->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(990.0, $this->branchBalance(self::GARLIC));

        $onlyPrep = $this->pushEvent('stock.count', ['staff_id' => 7, 'lines' => [['ingredient_id' => self::SAUCE, 'counted_units' => 500]]]);
        $this->assertSame('failed', $onlyPrep->json('data.results.0.status'));

        $restock = $this->pushEvent('restock.request', ['lines' => [
            ['ingredient_id' => self::SAUCE, 'quantity' => 1000],
            ['ingredient_id' => self::OIL, 'quantity' => 2000],
        ]]);
        $this->assertSame('processed', $restock->json('data.results.0.status'));
        $this->assertSame(1, $restock->json('data.results.0.result.lines'));
        $this->assertSame([self::OIL], DB::table('pos_restock_request_lines')->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all());

        $this->assertPrepHasNoStock();
    }

    public function test_product_waste_costs_a_cooked_item_through_its_prep(): void
    {
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => self::PLATTER, 'is_available' => true, 'stock_qty' => '5.000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->pushEvent('product.waste', ['staff_id' => 7, 'lines' => [['product_id' => self::PLATTER, 'qty' => 1, 'reason' => 'spoiled']]]);

        // 200 g chicken × 0.004 + 50 ml sauce × 0.00155 = 0.8775 a piece.
        $this->assertSame(0.8775, (float) DB::table('pos_product_stock_movements')
            ->where('product_id', self::PLATTER)->where('movement_type', 'waste')->value('unit_cost'));
    }
}
