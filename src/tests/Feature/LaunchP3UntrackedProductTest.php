<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\OrderLineSnapshotter;
use App\Models\Device;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-7 — an untracked product never deducts a leftover recipe at
 * sale. The portal refuses a recipe on an untracked product, but a product
 * switched to untracked outside the recipe editor keeps its old recipe rows;
 * neither a device order nor a QR order copies them.
 */
class LaunchP3UntrackedProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Water', 'base_price' => 0.500, 'status' => 'active', 'stock_mode' => 'untracked'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'l',
            'default_unit_cost' => '0.400000', 'status' => 'active',
        ] + $t);
        // A leftover recipe from when "Water" was made-to-order.
        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => 1, 'quantity' => '0.2500', 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
            ['product_id' => 2, 'ingredient_id' => 1, 'quantity' => '0.3000', 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => '10'] + $t);
        Device::factory()->paired('mdev_p37')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    public function test_a_device_sale_of_an_untracked_product_deducts_no_leftover_recipe(): void
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinute()->toIso8601String();
        $results = $this->withToken('mdev_p37')->postJson('/api/v1/device/sync/push', ['events' => [
            [
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.create', 'client_timestamp' => $at,
                'payload' => ['order' => [
                    'uuid' => $uuid, 'order_type' => 'to_go', 'source' => 'main_pos', 'staff_id' => 7, 'opened_at' => $at,
                    'subtotal_baisas' => 2000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 2000,
                    'lines' => [
                        ['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 500, 'line_total_baisas' => 500],
                        ['product_id' => 2, 'qty' => 1, 'unit_price_baisas' => 1500, 'line_total_baisas' => 1500],
                    ],
                ]],
            ],
            [
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
                'payload' => ['order_uuid' => $uuid, 'paid_at' => $at,
                    'payments' => [['method' => 'cash', 'amount_baisas' => 2000, 'change_given_baisas' => 0]]],
            ],
        ]])->assertOk()->json('data.results');
        $this->assertSame(['processed', 'processed'], array_column($results, 'status'));

        $this->assertNull(OrderItem::query()->where('product_id', 1)->sole()->recipe_snapshot_json);
        $this->assertNotNull(OrderItem::query()->where('product_id', 2)->sole()->recipe_snapshot_json);
        // Only the latte's 0.300 l left the shelf.
        $this->assertSame(-0.3, (float) DB::table('pos_stock_movements')->where('ingredient_id', 1)->sum('quantity'));
    }

    public function test_a_qr_order_of_an_untracked_product_copies_no_leftover_recipe(): void
    {
        $snapshots = app(OrderLineSnapshotter::class);

        $this->assertNull($snapshots->product(Product::query()->findOrFail(1))['recipe_snapshot_json']);
        $this->assertNotNull($snapshots->product(Product::query()->findOrFail(2))['recipe_snapshot_json']);
    }
}
