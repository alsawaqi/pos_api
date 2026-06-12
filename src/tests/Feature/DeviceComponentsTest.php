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
 * P-G2 — physical items (cups / lids) on the device pipeline.
 *
 *   - internal items NEVER reach /device/config (full), and a product
 *     flipped internal since the cursor surfaces in delta deleted.products
 *     so stale tiles purge;
 *   - paying an order consumes each line product's components from the
 *     branch unit stock (coffee = 1 x cup + 2 x napkin), with
 *     sale_consumption ledger rows naming the parent product;
 *   - voiding restores them; an un-stocked component no-ops.
 *
 * Catalogue: company 100 / branch 10 — sellable Coffee (untracked) with
 * components Cup (x1, internal) + Napkin (x2, internal); Cup + Napkin
 * hold 10 units each at the branch.
 */
class DeviceComponentsTest extends TestCase
{
    use RefreshDatabase;

    private const COFFEE = 1;

    private const CUP = 2;

    private const NAPKIN = 3;

    private function device(string $token = 'mdev_comp', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedComponents(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => self::COFFEE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Coffee', 'name_ar' => null, 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'untracked', 'is_internal' => false] + $t,
            ['id' => self::CUP, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cup 12oz', 'name_ar' => null, 'base_price' => 0, 'status' => 'active', 'stock_mode' => 'unit', 'is_internal' => true] + $t,
            ['id' => self::NAPKIN, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Napkin', 'name_ar' => null, 'base_price' => 0, 'status' => 'active', 'stock_mode' => 'unit', 'is_internal' => true] + $t,
        ]);
        DB::table('pos_product_components')->insert([
            ['product_id' => self::COFFEE, 'component_product_id' => self::CUP, 'quantity' => 1.000] + $t,
            ['product_id' => self::COFFEE, 'component_product_id' => self::NAPKIN, 'quantity' => 2.000] + $t,
        ]);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => self::CUP, 'is_available' => true, 'stock_qty' => 10.000] + $t,
            ['branch_id' => 10, 'product_id' => self::NAPKIN, 'is_available' => true, 'stock_qty' => 10.000] + $t,
        ]);
    }

    private function componentQty(int $productId): float
    {
        return (float) DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', $productId)
            ->value('stock_qty');
    }

    /** order.create + order.pay for [qty] coffees through the sync pipe. */
    private function sellCoffee(int $qty): string
    {
        $orderUuid = (string) Str::uuid();
        $this->withToken('mdev_comp')->postJson('/api/v1/device/sync/push', ['events' => [
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
                    'subtotal_baisas' => 1500 * $qty,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 1500 * $qty,
                    'lines' => [[
                        'product_id' => self::COFFEE,
                        'qty' => $qty,
                        'unit_price_baisas' => 1500,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 1500 * $qty,
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
                    'payments' => [['method' => 'cash', 'amount_baisas' => 1500 * $qty, 'change_given_baisas' => 0]],
                ],
            ],
        ]])->assertOk();

        return $orderUuid;
    }

    // ------------------------------------------------ config exclusion

    public function test_internal_items_never_reach_the_device_config(): void
    {
        $this->seedComponents();
        $this->device();

        $data = $this->withToken('mdev_comp')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $ids = collect($data['products'])->pluck('id')->all();
        $this->assertContains(self::COFFEE, $ids);
        $this->assertNotContains(self::CUP, $ids);
        $this->assertNotContains(self::NAPKIN, $ids);
    }

    public function test_a_product_flipped_internal_purges_via_the_delta(): void
    {
        $this->seedComponents();
        $this->device();

        $cursor = now()->subMinute()->toIso8601String();

        // The merchant flips Coffee to internal AFTER the device synced.
        DB::table('pos_products')->where('id', self::COFFEE)
            ->update(['is_internal' => true, 'updated_at' => now()]);

        $data = $this->withToken('mdev_comp')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($cursor))
            ->assertOk()
            ->json('data');

        $this->assertNotContains(self::COFFEE, collect($data['products'])->pluck('id')->all());
        $this->assertContains(self::COFFEE, $data['deleted']['products']);
    }

    // ------------------------------------------------ sale consumption

    public function test_paying_consumes_the_line_products_components(): void
    {
        $this->seedComponents();
        $this->device();

        $this->sellCoffee(2);

        // Coffee = 1 cup + 2 napkins per unit: cup 10-2=8, napkin 10-4=6.
        $this->assertEqualsWithDelta(8.0, $this->componentQty(self::CUP), 0.001);
        $this->assertEqualsWithDelta(6.0, $this->componentQty(self::NAPKIN), 0.001);

        // Ledger rows name the parent product.
        $cupRow = DB::table('pos_product_stock_movements')
            ->where('product_id', self::CUP)
            ->where('movement_type', 'sale_consumption')
            ->first();
        $this->assertNotNull($cupRow);
        $this->assertEqualsWithDelta(-2.0, (float) $cupRow->quantity, 0.001);
        $this->assertSame('component of #'.self::COFFEE, $cupRow->note);
    }

    public function test_voiding_restores_the_components(): void
    {
        $this->seedComponents();
        $this->device();

        $orderUuid = $this->sellCoffee(2);

        $this->withToken('mdev_comp')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'test'],
        ]]])->assertOk();

        $this->assertEqualsWithDelta(10.0, $this->componentQty(self::CUP), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->componentQty(self::NAPKIN), 0.001);
    }

    public function test_an_unstocked_component_noops(): void
    {
        $this->seedComponents();
        $this->device();

        // The napkin is not tracked at this branch anymore.
        DB::table('pos_branch_product')->where('product_id', self::NAPKIN)->delete();

        $this->sellCoffee(1);

        // The cup still moved; the napkin silently didn't.
        $this->assertEqualsWithDelta(9.0, $this->componentQty(self::CUP), 0.001);
        $this->assertSame(
            0,
            DB::table('pos_product_stock_movements')->where('product_id', self::NAPKIN)->count(),
        );
    }
}
