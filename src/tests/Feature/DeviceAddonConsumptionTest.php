<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PD3b — per-option consumption on the device pipeline.
 *
 *   - /device/config emits each addon's stock-usage lines;
 *   - order.create freezes them into consumption_snapshot_json and
 *     SUPERSEDES the legacy single-ingredient trio for that addon;
 *   - order.pay merges recipe/components with the option deltas, clamped
 *     at zero per ref (a removal never restocks), attributing up to the
 *     base as sale/component and the surplus as option consumption;
 *   - a linked product's OWN components now leave stock too (the P-G3
 *     packaging gap);
 *   - order.void restores everything exactly.
 *
 * Catalogue (company 100 / branch 10):
 *   BURGER (ingredient mode): recipe salad 0.050 + mayo 0.030; component
 *     BOX x1 (internal packaging, stock 10).
 *   PATTY (cooked, shelf 10). FRIES (unit, stock 10) with component
 *     FRIES_BOX x1 (internal packaging, stock 10).
 *   Options: EXTRA_PATTY (+1 patty), NO_SALAD (remove salad 0.050),
 *   EXTRA_SALAD (add salad 0.020), NO_SALAD_XL (remove salad 0.080 >
 *   recipe), LEGACY_MAYO (trio: mayo 0.010, NO lines), SIDE_FRIES
 *   (linked product FRIES, no lines).
 */
class DeviceAddonConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private const BURGER = 1;

    private const BOX = 2;

    private const PATTY = 3;

    private const FRIES = 4;

    private const FRIES_BOX = 5;

    private const SALAD = 11;

    private const MAYO = 12;

    private const EXTRA_PATTY = 50;

    private const NO_SALAD = 51;

    private const EXTRA_SALAD = 52;

    private const NO_SALAD_XL = 53;

    private const LEGACY_MAYO = 54;

    private const SIDE_FRIES = 55;

    private function device(string $token = 'mdev_optcons'): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedCatalogue(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => self::BURGER, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Burger', 'base_price' => 2.000, 'status' => 'active', 'stock_mode' => 'ingredient', 'is_internal' => false, 'internal_purpose' => null] + $t,
            ['id' => self::BOX, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Box', 'base_price' => 0, 'status' => 'active', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging'] + $t,
            ['id' => self::PATTY, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Patty', 'base_price' => 0.500, 'status' => 'active', 'stock_mode' => 'cooked', 'is_internal' => false, 'internal_purpose' => null] + $t,
            ['id' => self::FRIES, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Fries', 'base_price' => 0.800, 'status' => 'active', 'stock_mode' => 'unit', 'is_internal' => false, 'internal_purpose' => null] + $t,
            ['id' => self::FRIES_BOX, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Fries Box', 'base_price' => 0, 'status' => 'active', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging'] + $t,
        ]);

        DB::table('pos_ingredients')->insert([
            ['id' => self::SALAD, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Salad', 'unit' => 'kg', 'default_unit_cost' => 1.000, 'status' => 'active'] + $t,
            ['id' => self::MAYO, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Mayo', 'unit' => 'kg', 'default_unit_cost' => 2.000, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::SALAD, 'quantity' => 10.000] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::MAYO, 'quantity' => 10.000] + $t,
        ]);

        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::BURGER, 'ingredient_id' => self::SALAD, 'quantity' => 0.050, 'unit_at_set' => 'kg', 'sort_order' => 0] + $t,
            ['product_id' => self::BURGER, 'ingredient_id' => self::MAYO, 'quantity' => 0.030, 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_product_components')->insert([
            ['product_id' => self::BURGER, 'component_product_id' => self::BOX, 'quantity' => 1.000] + $t,
            ['product_id' => self::FRIES, 'component_product_id' => self::FRIES_BOX, 'quantity' => 1.000] + $t,
        ]);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => self::BOX, 'is_available' => true, 'stock_qty' => 10.000] + $t,
            ['branch_id' => 10, 'product_id' => self::PATTY, 'is_available' => true, 'stock_qty' => 10.000] + $t,
            ['branch_id' => 10, 'product_id' => self::FRIES, 'is_available' => true, 'stock_qty' => 10.000] + $t,
            ['branch_id' => 10, 'product_id' => self::FRIES_BOX, 'is_available' => true, 'stock_qty' => 10.000] + $t,
        ]);

        DB::table('pos_addon_groups')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Extras', 'selection_mode' => 'multi', 'is_global' => true, 'display_order' => 0, 'status' => 'active'] + $t,
        ]);
        $addonDefaults = ['ingredient_id' => null, 'ingredient_qty' => null, 'ingredient_unit' => null, 'linked_product_id' => null, 'status' => 'active'] + $t;
        DB::table('pos_addons')->insert([
            ['id' => self::EXTRA_PATTY, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Extra patty', 'price_delta' => 0.500] + $addonDefaults,
            ['id' => self::NO_SALAD, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'No salad', 'price_delta' => 0] + $addonDefaults,
            ['id' => self::EXTRA_SALAD, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Extra salad', 'price_delta' => 0.100] + $addonDefaults,
            ['id' => self::NO_SALAD_XL, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'No salad XL', 'price_delta' => 0] + $addonDefaults,
            ['id' => self::LEGACY_MAYO, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'More mayo', 'price_delta' => 0.050, 'ingredient_id' => self::MAYO, 'ingredient_qty' => 0.010, 'ingredient_unit' => 'kg'] + $addonDefaults,
            ['id' => self::SIDE_FRIES, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Side fries', 'price_delta' => 0.800, 'linked_product_id' => self::FRIES] + $addonDefaults,
        ]);

        $lineDefaults = ['ingredient_id' => null, 'component_product_id' => null, 'unit' => null, 'display_order' => 0] + $t;
        DB::table('pos_addon_consumptions')->insert([
            ['add_on_id' => self::EXTRA_PATTY, 'component_product_id' => self::PATTY, 'direction' => 'add', 'quantity' => 1.000] + $lineDefaults,
            ['add_on_id' => self::NO_SALAD, 'ingredient_id' => self::SALAD, 'direction' => 'remove', 'quantity' => 0.050, 'unit' => 'kg'] + $lineDefaults,
            ['add_on_id' => self::EXTRA_SALAD, 'ingredient_id' => self::SALAD, 'direction' => 'add', 'quantity' => 0.020, 'unit' => 'kg'] + $lineDefaults,
            ['add_on_id' => self::NO_SALAD_XL, 'ingredient_id' => self::SALAD, 'direction' => 'remove', 'quantity' => 0.080, 'unit' => 'kg'] + $lineDefaults,
        ]);
    }

    /** order.create + order.pay for ONE burger with the given addon ids. */
    private function sellBurger(array $addonIds, int $qty = 1): string
    {
        $orderUuid = (string) Str::uuid();
        $this->withToken('mdev_optcons')->postJson('/api/v1/device/sync/push', ['events' => [
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.create',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => ['order' => [
                    'uuid' => $orderUuid,
                    'order_type' => 'to_go',
                    'source' => 'main_pos',
                    'staff_id' => null,
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 2000 * $qty,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 2000 * $qty,
                    'lines' => [[
                        'product_id' => self::BURGER,
                        'qty' => $qty,
                        'unit_price_baisas' => 2000,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 2000 * $qty,
                        'addons' => array_map(static fn (int $id): array => [
                            'add_on_id' => $id,
                            'price_delta_baisas' => 0,
                        ], $addonIds),
                    ]],
                ]],
            ],
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.pay',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $orderUuid,
                    'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 2000 * $qty, 'change_given_baisas' => 0]],
                ],
            ],
        ]])->assertOk();

        return $orderUuid;
    }

    private function productQty(int $productId): float
    {
        return (float) DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', $productId)
            ->value('stock_qty');
    }

    private function ingredientQty(int $ingredientId): float
    {
        return (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)->where('ingredient_id', $ingredientId)
            ->value('quantity');
    }

    // ------------------------------------------------ config emission

    public function test_config_emits_each_options_consumption_lines(): void
    {
        $this->seedCatalogue();
        $this->device();

        $data = $this->withToken('mdev_optcons')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $addons = collect($data['addon_groups'])->firstWhere('id', 1)['addons'];
        $byId = collect($addons)->keyBy('id');

        $patty = $byId[self::EXTRA_PATTY]['consumption'];
        $this->assertCount(1, $patty);
        $this->assertSame('product', $patty[0]['type']);
        $this->assertSame(self::PATTY, $patty[0]['product_id']);
        $this->assertSame('add', $patty[0]['direction']);

        $noSalad = $byId[self::NO_SALAD]['consumption'];
        $this->assertSame('ingredient', $noSalad[0]['type']);
        $this->assertSame(self::SALAD, $noSalad[0]['ingredient_id']);
        $this->assertSame('remove', $noSalad[0]['direction']);
        $this->assertEqualsWithDelta(0.050, (float) $noSalad[0]['qty'], 0.0001);

        // Legacy trio option carries no lines.
        $this->assertSame([], $byId[self::LEGACY_MAYO]['consumption']);
    }

    // ------------------------------------------------ create-time freeze

    public function test_create_freezes_lines_and_supersedes_the_trio(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::NO_SALAD, self::LEGACY_MAYO]);

        $rows = DB::table('pos_order_item_addons')->orderBy('add_on_id')->get()->keyBy('add_on_id');

        // The lines option froze its snapshot and nulled the trio slot.
        $noSalad = $rows[self::NO_SALAD];
        $this->assertNotNull($noSalad->consumption_snapshot_json);
        $this->assertNull($noSalad->ingredient_snapshot_json);
        $frozen = json_decode((string) $noSalad->consumption_snapshot_json, true);
        $this->assertSame('remove', $frozen[0]['direction']);
        $this->assertEqualsWithDelta(0.050, (float) $frozen[0]['qty'], 0.0001);
        // Ingredient lines freeze the live unit cost like recipe lines do.
        $this->assertEqualsWithDelta(1.000, (float) $frozen[0]['unit_cost'], 0.0001);

        // The legacy option kept the trio path, no lines.
        $legacy = $rows[self::LEGACY_MAYO];
        $this->assertNull($legacy->consumption_snapshot_json);
        $this->assertNotNull($legacy->ingredient_snapshot_json);
    }

    // ------------------------------------------------ pay-time merge

    public function test_a_removal_cancels_the_recipe_line_and_an_add_consumes_extra_stock(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::NO_SALAD, self::EXTRA_PATTY]);

        // Salad: recipe 0.050 - removal 0.050 = 0 -> untouched, NO movement.
        $this->assertEqualsWithDelta(10.0, $this->ingredientQty(self::SALAD), 0.001);
        $this->assertSame(0, DB::table('pos_stock_movements')->where('ingredient_id', self::SALAD)->count());

        // Mayo: the untouched recipe line consumed normally.
        $this->assertEqualsWithDelta(9.970, $this->ingredientQty(self::MAYO), 0.001);

        // The extra patty left the cooked shelf as OPTION consumption.
        $this->assertEqualsWithDelta(9.0, $this->productQty(self::PATTY), 0.001);
        $pattyRow = DB::table('pos_product_stock_movements')->where('product_id', self::PATTY)->first();
        $this->assertSame('option consumption', $pattyRow->note);

        // The burger's own box still consumed as a component.
        $this->assertEqualsWithDelta(9.0, $this->productQty(self::BOX), 0.001);
    }

    public function test_an_add_on_top_of_the_recipe_splits_sale_and_option_attribution(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::EXTRA_SALAD]);

        // 0.050 recipe + 0.020 option = 0.070 total off the shelf.
        $this->assertEqualsWithDelta(9.930, $this->ingredientQty(self::SALAD), 0.001);

        $movements = DB::table('pos_stock_movements')
            ->where('ingredient_id', self::SALAD)
            ->orderBy('movement_type')
            ->get();
        $this->assertCount(2, $movements);
        $this->assertSame('addon_consumption', $movements[0]->movement_type);
        $this->assertEqualsWithDelta(-0.020, (float) $movements[0]->quantity, 0.0001);
        $this->assertSame('sale_consumption', $movements[1]->movement_type);
        $this->assertEqualsWithDelta(-0.050, (float) $movements[1]->quantity, 0.0001);
    }

    public function test_a_removal_larger_than_the_recipe_clamps_at_zero(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::NO_SALAD_XL]);

        // remove 0.080 > recipe 0.050 -> clamped: nothing moves, nothing
        // restocks.
        $this->assertEqualsWithDelta(10.0, $this->ingredientQty(self::SALAD), 0.001);
        $this->assertSame(0, DB::table('pos_stock_movements')->where('ingredient_id', self::SALAD)->count());
    }

    public function test_the_legacy_trio_still_consumes(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::LEGACY_MAYO]);

        // Recipe 0.030 (sale) + trio 0.010 (addon).
        $this->assertEqualsWithDelta(9.960, $this->ingredientQty(self::MAYO), 0.001);
        $this->assertSame(1, DB::table('pos_stock_movements')
            ->where('ingredient_id', self::MAYO)
            ->where('movement_type', 'addon_consumption')
            ->count());
    }

    // ------------------------------------------------ linked-product gap

    public function test_a_linked_products_own_components_now_consume(): void
    {
        $this->seedCatalogue();
        $this->device();

        $this->sellBurger([self::SIDE_FRIES]);

        // The fries left the shelf (pre-PD3b behaviour)...
        $this->assertEqualsWithDelta(9.0, $this->productQty(self::FRIES), 0.001);
        // ...and now their box does too (the P-G3 packaging gap).
        $this->assertEqualsWithDelta(9.0, $this->productQty(self::FRIES_BOX), 0.001);
        $boxRow = DB::table('pos_product_stock_movements')->where('product_id', self::FRIES_BOX)->first();
        $this->assertSame('component of add-on #'.self::FRIES, $boxRow->note);
    }

    // ------------------------------------------------ void symmetry

    public function test_voiding_restores_option_consumption_exactly(): void
    {
        $this->seedCatalogue();
        $this->device();

        $orderUuid = $this->sellBurger([self::NO_SALAD, self::EXTRA_PATTY, self::EXTRA_SALAD, self::SIDE_FRIES]);

        $this->withToken('mdev_optcons')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'test'],
        ]]])->assertOk();

        $this->assertEqualsWithDelta(10.0, $this->ingredientQty(self::SALAD), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->ingredientQty(self::MAYO), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->productQty(self::PATTY), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->productQty(self::BOX), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->productQty(self::FRIES), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->productQty(self::FRIES_BOX), 0.001);
    }
}
