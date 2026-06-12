<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P-G3 — product-as-add-on on the device pipeline.
 *
 *   - /device/config add-ons carry linked_product_id (the device greys
 *     the option when that product is sold out);
 *   - paying an order consumes the FROZEN product behind each selected
 *     add-on by its type: cooked -> branch shelf -1 per parent unit
 *     (same pool as the standalone tile), made-to-order -> its frozen
 *     recipe via addon_consumption rows; voiding restores both;
 *   - classic single-ingredient add-ons keep working unchanged.
 *
 * Catalogue: company 100 / branch 10 — sellable Coffee (untracked);
 * Cake (cooked, shelf 5) behind the "Cake slice" add-on; Juice
 * (made-to-order, 0.3 L Orange per unit, Orange 10 L on the shelf)
 * behind the "Fresh juice" add-on.
 */
class DeviceProductAddonTest extends TestCase
{
    use RefreshDatabase;

    private const COFFEE = 1;

    private const CAKE = 2;

    private const JUICE = 3;

    private const ORANGE = 1;

    private const ADDON_CAKE = 11;

    private const ADDON_JUICE = 12;

    private function device(string $token = 'mdev_paddon', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedAddonCatalogue(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => self::COFFEE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Coffee', 'name_ar' => null, 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'untracked', 'is_internal' => false] + $t,
            ['id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake', 'name_ar' => null, 'base_price' => 5.000, 'status' => 'active', 'stock_mode' => 'cooked', 'is_internal' => false] + $t,
            ['id' => self::JUICE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Juice', 'name_ar' => null, 'base_price' => 2.000, 'status' => 'active', 'stock_mode' => 'ingredient', 'is_internal' => false] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => self::ORANGE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Orange', 'unit' => 'l', 'default_unit_cost' => 0.600, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::JUICE, 'ingredient_id' => self::ORANGE, 'quantity' => 0.300, 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => self::CAKE, 'is_available' => true, 'stock_qty' => 5.000] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::ORANGE, 'quantity' => 10.000] + $t,
        ]);
        DB::table('pos_addon_groups')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Extras', 'selection_mode' => 'multiple', 'is_global' => true, 'display_order' => 0, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addons')->insert([
            ['id' => self::ADDON_CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Cake slice', 'price_delta' => 1.500, 'linked_product_id' => self::CAKE, 'status' => 'active'] + $t,
            ['id' => self::ADDON_JUICE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Fresh juice', 'price_delta' => 1.000, 'linked_product_id' => self::JUICE, 'status' => 'active'] + $t,
        ]);
    }

    /** Sell [qty] coffees carrying the given add-on ids; returns order uuid. */
    private function sellWithAddons(int $qty, array $addonIds): string
    {
        $orderUuid = (string) Str::uuid();
        $this->withToken('mdev_paddon')->postJson('/api/v1/device/sync/push', ['events' => [
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
                    'subtotal_baisas' => 3000 * $qty,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 3000 * $qty,
                    'lines' => [[
                        'product_id' => self::COFFEE,
                        'qty' => $qty,
                        'unit_price_baisas' => 3000,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 3000 * $qty,
                        'addons' => array_map(
                            static fn (int $id): array => ['add_on_id' => $id, 'price_delta_baisas' => 1500],
                            $addonIds,
                        ),
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
                    'payments' => [['method' => 'cash', 'amount_baisas' => 3000 * $qty, 'change_given_baisas' => 0]],
                ],
            ],
        ]])->assertOk();

        return $orderUuid;
    }

    private function cakeShelf(): float
    {
        return (float) DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', self::CAKE)
            ->value('stock_qty');
    }

    private function orangeBalance(): float
    {
        return (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)->where('ingredient_id', self::ORANGE)
            ->value('quantity');
    }

    public function test_config_addons_carry_the_linked_product_id(): void
    {
        $this->seedAddonCatalogue();
        $this->device();

        $data = $this->withToken('mdev_paddon')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $addons = collect($data['addon_groups'])->firstWhere('id', 1)['addons'];
        $cakeSlice = collect($addons)->firstWhere('id', self::ADDON_CAKE);
        $this->assertSame(self::CAKE, $cakeSlice['linked_product_id']);
        $juice = collect($addons)->firstWhere('id', self::ADDON_JUICE);
        $this->assertSame(self::JUICE, $juice['linked_product_id']);
    }

    public function test_a_cooked_linked_addon_consumes_the_shelf_pool(): void
    {
        $this->seedAddonCatalogue();
        $this->device();

        $this->sellWithAddons(2, [self::ADDON_CAKE]);

        // 2 coffees x 1 cake slice = 2 cakes off the SAME shelf pool.
        $this->assertEqualsWithDelta(3.0, $this->cakeShelf(), 0.001);

        $row = DB::table('pos_product_stock_movements')
            ->where('product_id', self::CAKE)
            ->where('movement_type', 'sale_consumption')
            ->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(-2.0, (float) $row->quantity, 0.001);
        $this->assertSame('sold as add-on', $row->note);

        // The freeze landed on the order rows (reporting + void symmetry).
        $addonRow = DB::table('pos_order_item_addons')->where('add_on_id', self::ADDON_CAKE)->first();
        $this->assertSame(self::CAKE, (int) $addonRow->linked_product_id);
        $this->assertNotNull($addonRow->product_snapshot_json);
    }

    public function test_a_made_to_order_linked_addon_consumes_its_recipe(): void
    {
        $this->seedAddonCatalogue();
        $this->device();

        $this->sellWithAddons(2, [self::ADDON_JUICE]);

        // 2 coffees x 1 juice x 0.3 L = 0.6 L of orange.
        $this->assertEqualsWithDelta(9.4, $this->orangeBalance(), 0.001);

        $row = DB::table('pos_stock_movements')
            ->where('ingredient_id', self::ORANGE)
            ->where('movement_type', 'addon_consumption')
            ->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(-0.6, (float) $row->quantity, 0.001);

        // The cake shelf is untouched by the juice add-on.
        $this->assertEqualsWithDelta(5.0, $this->cakeShelf(), 0.001);
    }

    public function test_voiding_restores_the_linked_product_stock(): void
    {
        $this->seedAddonCatalogue();
        $this->device();

        $orderUuid = $this->sellWithAddons(2, [self::ADDON_CAKE, self::ADDON_JUICE]);
        $this->assertEqualsWithDelta(3.0, $this->cakeShelf(), 0.001);
        $this->assertEqualsWithDelta(9.4, $this->orangeBalance(), 0.001);

        $this->withToken('mdev_paddon')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'test'],
        ]]])->assertOk();

        $this->assertEqualsWithDelta(5.0, $this->cakeShelf(), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->orangeBalance(), 0.001);
    }
}
