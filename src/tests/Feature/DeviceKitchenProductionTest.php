<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P-G1 — kitchen production for COOKED products.
 *
 *   GET  /device/kitchen                      screen data (cooked products
 *                                             + computed max + active)
 *   POST /device/productions                  start (locked recipe x qty,
 *                                             extras, immediate deduction)
 *   POST /device/productions/{uuid}/finish    +qty shelf stock, duration
 *   POST /device/productions/{uuid}/cancel    manager PIN, ingredients back
 *
 * Catalogue: company 100 / branch 10 — a cooked Cake (recipe: 0.5 kg Flour
 * + 0.2 kg Sugar per piece), a made-to-order Latte, branch balances
 * Flour 5 kg / Sugar 4 kg. Manager 'Mona' (PIN 4321) for cancel approval;
 * chef 'Sami' (staff 7) runs the batches.
 */
class DeviceKitchenProductionTest extends TestCase
{
    use RefreshDatabase;

    private const CAKE = 1;

    private const LATTE = 2;

    private const FLOUR = 1;

    private const SUGAR = 2;

    private const CHEF = 7;

    private function device(string $token = 'mdev_kitchen', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedKitchen(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake', 'name_ar' => 'كيكة', 'base_price' => 5.000, 'status' => 'active', 'stock_mode' => 'cooked'] + $t,
            ['id' => self::LATTE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'name_ar' => null, 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => self::FLOUR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour', 'unit' => 'kg', 'default_unit_cost' => 0.300, 'status' => 'active'] + $t,
            ['id' => self::SUGAR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sugar', 'unit' => 'kg', 'default_unit_cost' => 0.500, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::CAKE, 'ingredient_id' => self::FLOUR, 'quantity' => 0.500, 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
            ['product_id' => self::CAKE, 'ingredient_id' => self::SUGAR, 'quantity' => 0.200, 'unit_at_set' => 'kg', 'sort_order' => 2] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::FLOUR, 'quantity' => 5.000] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::SUGAR, 'quantity' => 4.000] + $t,
        ]);
        DB::table('pos_staff')->insert([
            ['id' => self::CHEF, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Sami', 'pin_hash' => Hash::make('1111'), 'position' => 'kitchen', 'status' => 'active'] + $t,
            ['id' => 8, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Mona', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active'] + $t,
        ]);
    }

    private function start(string $token, array $overrides = []): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/productions', array_merge([
            'product_id' => self::CAKE,
            'quantity' => 4,
            'staff_id' => self::CHEF,
            'extras' => [],
        ], $overrides));
    }

    private function balance(int $ingredientId): float
    {
        return (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)
            ->where('ingredient_id', $ingredientId)
            ->value('quantity');
    }

    // ------------------------------------------------ GET /device/kitchen

    public function test_kitchen_lists_cooked_products_with_computed_max(): void
    {
        $this->seedKitchen();
        $this->device();

        $res = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/kitchen')->assertOk();

        $products = $res->json('data.products');
        $this->assertCount(1, $products); // the Latte (ingredient mode) is NOT a kitchen product
        $this->assertSame('Cake', $products[0]['name']);
        // Flour allows floor(5/0.5)=10, Sugar allows floor(4/0.2)=20 -> 10.
        $this->assertSame(10, $products[0]['max_producible']);
        $this->assertNull($products[0]['branch_stock_qty']); // nothing produced yet
        $this->assertCount(2, $products[0]['recipe']);
        $this->assertSame('Flour', $products[0]['recipe'][0]['name']);
        // JSON round-trips whole floats as ints — compare loosely.
        $this->assertEqualsWithDelta(5.0, $products[0]['recipe'][0]['branch_balance'], 0.001);

        // The extras picker gets every active ingredient + balance.
        $this->assertCount(2, $res->json('data.ingredients'));
        $this->assertSame([], $res->json('data.active'));
    }

    public function test_kitchen_respects_branch_availability(): void
    {
        $this->seedKitchen();
        $this->device();
        // The cake is explicitly NOT available at branch 10.
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => self::CAKE, 'is_available' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/kitchen')->assertOk();
        $this->assertSame([], $res->json('data.products'));
    }

    public function test_kitchen_requires_an_assigned_device(): void
    {
        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_unassigned')->getJson('/api/v1/device/kitchen')
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'device_unassigned');
    }

    // ------------------------------------------------ start

    public function test_start_locks_recipe_deducts_ingredients_and_records_lines(): void
    {
        $this->seedKitchen();
        $this->device();

        $res = $this->start('mdev_kitchen', [
            'quantity' => 4,
            'extras' => [['ingredient_id' => self::SUGAR, 'quantity' => 0.1]],
        ])->assertCreated();

        $production = $res->json('data.production');
        $this->assertSame('in_progress', $production['status']);
        $this->assertEqualsWithDelta(4.0, $production['quantity'], 0.001);
        $this->assertSame('Sami', $production['started_by']);
        $this->assertNotNull($production['started_at']);

        // Lines: locked std (recipe x 4) + the declared extra, separately.
        $this->assertCount(3, $production['lines']);
        $std = array_values(array_filter($production['lines'], fn (array $l): bool => ! $l['is_extra']));
        $extra = array_values(array_filter($production['lines'], fn (array $l): bool => $l['is_extra']));
        $this->assertEqualsWithDelta(2.0, $std[0]['quantity'], 0.001);  // 0.5 x 4 flour
        $this->assertEqualsWithDelta(0.8, $std[1]['quantity'], 0.001);  // 0.2 x 4 sugar
        $this->assertCount(1, $extra);
        $this->assertEqualsWithDelta(0.1, $extra[0]['quantity'], 0.001);

        // Balances moved immediately: flour 5-2=3, sugar 4-0.8-0.1=3.1.
        $this->assertEqualsWithDelta(3.0, $this->balance(self::FLOUR), 0.001);
        $this->assertEqualsWithDelta(3.1, $this->balance(self::SUGAR), 0.001);

        // Ledger rows: negative production_consumption per line.
        $movements = DB::table('pos_stock_movements')
            ->where('movement_type', 'production_consumption')
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $movements);
        $this->assertEqualsWithDelta(-2.0, (float) $movements[0]->quantity, 0.001);
        $this->assertSame('pos_productions', $movements[0]->reference_type);
        $this->assertSame(self::CHEF, (int) $movements[0]->recorded_by_pos_staff_id);

        // The kitchen screen now shows the active batch + a reduced max.
        $kitchen = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/kitchen')->assertOk();
        $this->assertCount(1, $kitchen->json('data.active'));
        $this->assertSame(6, $kitchen->json('data.products.0.max_producible')); // floor(3/0.5)
    }

    public function test_start_refuses_when_ingredients_cannot_cover_the_quantity(): void
    {
        $this->seedKitchen();
        $this->device();

        // 11 cakes need 5.5 kg flour; the branch has 5.
        $this->start('mdev_kitchen', ['quantity' => 11])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'production_rejected');

        // Nothing moved, nothing recorded.
        $this->assertEqualsWithDelta(5.0, $this->balance(self::FLOUR), 0.001);
        $this->assertSame(0, DB::table('pos_productions')->count());
        $this->assertSame(0, DB::table('pos_stock_movements')->count());
    }

    public function test_start_refuses_a_non_cooked_product(): void
    {
        $this->seedKitchen();
        $this->device();

        $this->start('mdev_kitchen', ['product_id' => self::LATTE])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'production_rejected');
    }

    public function test_start_refuses_a_foreign_company_product(): void
    {
        $this->seedKitchen();
        $this->device();
        DB::table('pos_products')->insert([
            'id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign Cake',
            'base_price' => 1, 'status' => 'active', 'stock_mode' => 'cooked',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->start('mdev_kitchen', ['product_id' => 99])->assertStatus(422);
        $this->assertSame(0, DB::table('pos_productions')->count());
    }

    public function test_start_refuses_a_product_unavailable_at_the_branch(): void
    {
        $this->seedKitchen();
        $this->device();
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => self::CAKE, 'is_available' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->start('mdev_kitchen')->assertStatus(422);
    }

    public function test_start_validates_the_payload(): void
    {
        $this->seedKitchen();
        $this->device();

        $this->withToken('mdev_kitchen')->postJson('/api/v1/device/productions', [
            'product_id' => self::CAKE,
            'quantity' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
    }

    // ------------------------------------------------ finish

    public function test_finish_lands_pieces_in_shelf_stock_and_records_duration(): void
    {
        $this->seedKitchen();
        $this->device();

        $uuid = $this->start('mdev_kitchen', ['quantity' => 4])->json('data.production.uuid');

        // Pretend the batch has been cooking for 10 minutes.
        DB::table('pos_productions')->where('uuid', $uuid)->update(['started_at' => now()->subMinutes(10)]);

        $res = $this->withToken('mdev_kitchen')
            ->postJson("/api/v1/device/productions/{$uuid}/finish", ['staff_id' => self::CHEF])
            ->assertOk();

        $production = $res->json('data.production');
        $this->assertSame('finished', $production['status']);
        $this->assertNotNull($production['finished_at']);
        $this->assertEqualsWithDelta(600, $production['duration_seconds'], 5);

        // Shelf stock: the pivot row was created at 0 and bumped to 4.
        $row = DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', self::CAKE)->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(4.0, (float) $row->stock_qty, 0.001);
        $this->assertTrue((bool) $row->is_available);

        // Product ledger: one positive 'produced' row, branch side.
        $ledger = DB::table('pos_product_stock_movements')->where('movement_type', 'produced')->get();
        $this->assertCount(1, $ledger);
        $this->assertEqualsWithDelta(4.0, (float) $ledger[0]->quantity, 0.001);
        $this->assertSame(10, (int) $ledger[0]->branch_id);
        $this->assertSame('pos_productions', $ledger[0]->reference_type);

        // And the kitchen screen reflects the new shelf count.
        $kitchen = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/kitchen')->assertOk();
        $this->assertEqualsWithDelta(4.0, $kitchen->json('data.products.0.branch_stock_qty'), 0.001);
        $this->assertSame([], $kitchen->json('data.active'));
    }

    public function test_finish_refuses_a_batch_that_is_not_in_progress(): void
    {
        $this->seedKitchen();
        $this->device();

        $uuid = $this->start('mdev_kitchen')->json('data.production.uuid');
        $this->withToken('mdev_kitchen')->postJson("/api/v1/device/productions/{$uuid}/finish")->assertOk();

        // Second finish: the batch already closed.
        $this->withToken('mdev_kitchen')->postJson("/api/v1/device/productions/{$uuid}/finish")
            ->assertStatus(422);

        // The shelf got the pieces exactly once.
        $row = DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', self::CAKE)->first();
        $this->assertEqualsWithDelta(4.0, (float) $row->stock_qty, 0.001);
    }

    public function test_finish_refuses_a_foreign_batch(): void
    {
        $this->seedKitchen();
        $this->device();
        $uuid = $this->start('mdev_kitchen')->json('data.production.uuid');

        // A device of another company cannot see the batch. The viaRequest
        // guard caches the resolved device within a test — reset it so the
        // second request authenticates as the foreign device.
        Device::factory()->paired('mdev_foreign')->create(['company_id' => 200, 'branch_id' => 20]);
        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_foreign')->postJson("/api/v1/device/productions/{$uuid}/finish")
            ->assertStatus(422);
    }

    // ------------------------------------------------ cancel

    public function test_cancel_returns_the_ingredients_with_a_manager_pin(): void
    {
        $this->seedKitchen();
        $this->device();

        $uuid = $this->start('mdev_kitchen', [
            'quantity' => 4,
            'extras' => [['ingredient_id' => self::SUGAR, 'quantity' => 0.1]],
        ])->json('data.production.uuid');
        $this->assertEqualsWithDelta(3.0, $this->balance(self::FLOUR), 0.001);

        $res = $this->withToken('mdev_kitchen')
            ->postJson("/api/v1/device/productions/{$uuid}/cancel", ['pin' => '4321', 'staff_id' => self::CHEF])
            ->assertOk();

        $this->assertSame('cancelled', $res->json('data.production.status'));

        // Balances fully restored (std + extra).
        $this->assertEqualsWithDelta(5.0, $this->balance(self::FLOUR), 0.001);
        $this->assertEqualsWithDelta(4.0, $this->balance(self::SUGAR), 0.001);

        // Positive production_return rows, one per line.
        $this->assertSame(3, DB::table('pos_stock_movements')->where('movement_type', 'production_return')->count());

        // The approver (Mona, the manager whose PIN passed) is recorded.
        $row = DB::table('pos_productions')->where('uuid', $uuid)->first();
        $this->assertSame(8, (int) $row->cancel_approved_by_staff_id);
        $this->assertSame(self::CHEF, (int) $row->cancelled_by_staff_id);
        $this->assertNotNull($row->cancelled_at);
    }

    public function test_cancel_refuses_a_bad_pin(): void
    {
        $this->seedKitchen();
        $this->device();
        $uuid = $this->start('mdev_kitchen')->json('data.production.uuid');

        // Wrong PIN and a non-approval position's PIN both fail identically.
        foreach (['9999', '1111'] as $pin) {
            $this->withToken('mdev_kitchen')
                ->postJson("/api/v1/device/productions/{$uuid}/cancel", ['pin' => $pin])
                ->assertStatus(401)
                ->assertJsonPath('errors.0.code', 'invalid_pin');
        }

        // Nothing came back; the batch is still in progress.
        $this->assertEqualsWithDelta(3.0, $this->balance(self::FLOUR), 0.001);
        $this->assertSame('in_progress', DB::table('pos_productions')->where('uuid', $uuid)->value('status'));
    }

    public function test_cancel_then_finish_is_refused(): void
    {
        $this->seedKitchen();
        $this->device();
        $uuid = $this->start('mdev_kitchen')->json('data.production.uuid');

        $this->withToken('mdev_kitchen')
            ->postJson("/api/v1/device/productions/{$uuid}/cancel", ['pin' => '4321'])
            ->assertOk();

        $this->withToken('mdev_kitchen')->postJson("/api/v1/device/productions/{$uuid}/finish")
            ->assertStatus(422);
    }

    // ------------------------------------------------ config + sale path

    public function test_config_emits_the_kitchen_positions_setting(): void
    {
        $this->seedKitchen();
        $this->device();

        // Default (no policy row): the kitchen role ALWAYS has access, so the
        // emitted list is kitchen-only — there is NO managers-only fallback.
        $res = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/config')->assertOk();
        $this->assertSame(['kitchen'], $res->json('data.settings.kitchen_positions'));

        // A merchant-ticked role is unioned with the always-present kitchen role
        // (trimmed; deduped). 'kitchen' is implicit and appended once.
        DB::table('pos_company_settings')->insert([
            'company_id' => 100, 'key' => 'kitchen_positions',
            'value' => json_encode(['cashier ', 'manager']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $res = $this->withToken('mdev_kitchen')->getJson('/api/v1/device/config')->assertOk();
        $this->assertSame(['cashier', 'manager', 'kitchen'], $res->json('data.settings.kitchen_positions'));
    }

    public function test_selling_a_cooked_product_moves_shelf_stock_not_ingredients(): void
    {
        $this->seedKitchen();
        $this->device();

        // Produce 4 cakes so the shelf holds stock.
        $uuid = $this->start('mdev_kitchen', ['quantity' => 4])->json('data.production.uuid');
        $this->withToken('mdev_kitchen')->postJson("/api/v1/device/productions/{$uuid}/finish")->assertOk();
        $flourAfterProduction = $this->balance(self::FLOUR);

        // Sell 2 cakes through the normal sync pipeline.
        $orderUuid = (string) Str::uuid();
        $this->withToken('mdev_kitchen')->postJson('/api/v1/device/sync/push', ['events' => [
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.create',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => ['order' => [
                    'uuid' => $orderUuid,
                    'order_type' => 'to_go',
                    'source' => 'main_pos',
                    'staff_id' => self::CHEF,
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 10000,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 10000,
                    'lines' => [[
                        'product_id' => self::CAKE,
                        'qty' => 2,
                        'unit_price_baisas' => 5000,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 10000,
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
                    'payments' => [['method' => 'cash', 'amount_baisas' => 10000, 'change_given_baisas' => 0]],
                ],
            ],
        ]])->assertOk();

        // The cooked item froze NO recipe snapshot (production already
        // consumed the ingredients) — so paying moved ONLY the shelf count.
        $this->assertNull(
            DB::table('pos_order_items')->where('product_id', self::CAKE)->value('recipe_snapshot_json'),
        );
        $this->assertEqualsWithDelta($flourAfterProduction, $this->balance(self::FLOUR), 0.001);
        $this->assertSame(0, DB::table('pos_stock_movements')->where('movement_type', 'sale_consumption')->count());

        $row = DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', self::CAKE)->first();
        $this->assertEqualsWithDelta(2.0, (float) $row->stock_qty, 0.001); // 4 produced - 2 sold

        // And the product ledger carries both sides of the story.
        $this->assertSame(1, DB::table('pos_product_stock_movements')->where('movement_type', 'produced')->count());
        $this->assertSame(1, DB::table('pos_product_stock_movements')->where('movement_type', 'sale_consumption')->count());
    }
}
