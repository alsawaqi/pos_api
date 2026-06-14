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
 * The product.waste sync handler: a device records that cooked or bought-in
 * products on its branch shelf were wasted — a signed-negative 'waste'
 * ProductStockMovement (with reason + frozen cost) + the shelf decrement. The
 * merchant Loss/Waste report surfaces it. Wastage is never an expense.
 */
class DeviceSyncProductWasteTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_w'): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedProduct(int $id, string $mode, ?string $costPrice, string $name = 'Item'): void
    {
        DB::table('pos_products')->insert([
            'id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => null,
            'name' => $name, 'base_price' => '1.000', 'cost_price' => $costPrice,
            'stock_mode' => $mode, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedShelf(int $productId, string $qty): void
    {
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $productId, 'is_available' => true,
            'stock_qty' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function wasteEvent(array $payload): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'product.waste',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events, string $token = 'mdev_w'): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_wastes_a_bought_in_product_with_reason_and_frozen_cost(): void
    {
        $this->device();
        $this->seedProduct(1, 'unit', '0.200', 'Cola');
        $this->seedShelf(1, '10.000');

        $res = $this->push([$this->wasteEvent([
            'lines' => [['product_id' => 1, 'qty' => 3, 'reason' => 'expired']],
            'staff_id' => 7,
        ])])->assertOk();

        $r = $res->json('data.results.0');
        $this->assertSame('processed', $r['status']);
        $this->assertSame(1, $r['result']['wasted_lines']);

        $this->assertDatabaseHas('pos_product_stock_movements', [
            'product_id' => 1, 'branch_id' => 10, 'movement_type' => 'waste',
            'reason' => 'expired', 'quantity' => '-3.000', 'unit_cost' => '0.200',
        ]);
        // Shelf 10 -> 7.
        $this->assertSame(7.0, (float) DB::table('pos_branch_product')->where('product_id', 1)->value('stock_qty'));
    }

    public function test_cooked_product_waste_is_valued_at_the_recipe_cost(): void
    {
        $this->device();
        $this->seedProduct(2, 'cooked', null, 'Chapati');
        $this->seedShelf(2, '10.000');
        DB::table('pos_ingredients')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour',
            'unit' => 'kg', 'default_unit_cost' => '0.040', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_product_recipes')->insert([
            'product_id' => 2, 'ingredient_id' => 1, 'quantity' => '2.000', 'unit_at_set' => 'kg',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->push([$this->wasteEvent([
            'lines' => [['product_id' => 2, 'qty' => 5, 'reason' => 'spoiled']],
        ])])->assertOk();

        // Recipe cost = 2.000 * 0.040 = 0.080 per chapati, frozen.
        $this->assertDatabaseHas('pos_product_stock_movements', [
            'product_id' => 2, 'movement_type' => 'waste', 'unit_cost' => '0.080',
        ]);
    }

    public function test_cannot_waste_more_than_the_shelf_holds(): void
    {
        $this->device();
        $this->seedProduct(1, 'unit', '0.200');
        $this->seedShelf(1, '2.000');

        $res = $this->push([$this->wasteEvent([
            'lines' => [['product_id' => 1, 'qty' => 5, 'reason' => 'dropped']],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertSame(2.0, (float) DB::table('pos_branch_product')->where('product_id', 1)->value('stock_qty'));
        $this->assertDatabaseMissing('pos_product_stock_movements', ['product_id' => 1, 'movement_type' => 'waste']);
    }

    public function test_refuses_an_ineligible_product(): void
    {
        $this->device();
        $this->seedProduct(3, 'ingredient', '1.000'); // made-to-order: no branch shelf
        $this->seedShelf(3, '10.000');

        $res = $this->push([$this->wasteEvent([
            'lines' => [['product_id' => 3, 'qty' => 1, 'reason' => 'expired']],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
    }

    public function test_does_not_waste_another_company_product(): void
    {
        $this->device();
        // Product belongs to company 200, not the device's 100.
        DB::table('pos_products')->insert([
            'id' => 9, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Secret',
            'base_price' => '1.000', 'cost_price' => '5.000', 'stock_mode' => 'unit', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->push([$this->wasteEvent([
            'lines' => [['product_id' => 9, 'qty' => 1, 'reason' => 'expired']],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
    }
}
