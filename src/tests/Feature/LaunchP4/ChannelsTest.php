<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use App\Actions\Device\Production\FinishProductionAction;
use App\Actions\Device\Production\StartProductionAction;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LaunchP4Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P4 A4 — channels and branch scope (owner decision 8, H6, M2).
 *
 *  - The device config sends only active products sold at the branch by
 *    their branch scope, each with its channel flags, delivery prices with
 *    `listed` (a blank price resolved), and the "Apply to every product"
 *    groups; whatever stops being sellable is purged on a delta.
 *  - Stock rows never restrict: a shelf row at another branch leaves an
 *    'all' product on sale everywhere, in the config, on QR and in the
 *    kitchen; a batch's new shelf row never restricts or grants.
 *  - QR is an in-store channel: a product not sold in store is off the
 *    menu and refused.
 */
final class ChannelsTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function branchRow(int $productId, int $branchId, bool $available, ?string $stock = null): void
    {
        DB::table('pos_branch_product')->insert(['branch_id' => $branchId, 'product_id' => $productId, 'is_available' => $available,
            'stock_qty' => $stock, 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00']);
    }

    public function test_the_device_config_sends_only_sellable_products_with_their_channels(): void
    {
        $this->p4Device('mdev_p4_channels');
        $latte = $this->p4Product('Latte');
        $this->branchRow($latte, 11, true, '4.000');                       // a shelf row elsewhere (H6)
        $cake = $this->p4Product('Cake', '1.500', ['branch_scope' => 'selected']);
        $this->branchRow($cake, 10, true);
        $pie = $this->p4Product('Pie', '1.500', ['branch_scope' => 'selected']);
        $this->branchRow($pie, 11, true);
        $tea = $this->p4Product('Tea');
        $this->branchRow($tea, 10, false);                                   // switched off here
        $old = $this->p4Product('Old', '1.000', ['status' => 'inactive']);
        $burger = $this->p4Product('Burger', '2.000', ['delivery_price' => '2.200', 'sold_in_store' => false]);
        $providers = [];
        foreach (['Talabat', 'Careem'] as $name) {
            $providers[] = (int) DB::table('pos_delivery_providers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('pos_product_delivery_prices')->insert([
            ['product_id' => $burger, 'delivery_provider_id' => $providers[0], 'company_id' => 100, 'price' => null, 'listed' => true],
            ['product_id' => $burger, 'delivery_provider_id' => $providers[1], 'company_id' => 100, 'price' => '2.500', 'listed' => false],
        ]);
        $global = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Sugar', 'is_global' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $products = collect($this->withToken('mdev_p4_channels')->getJson('/api/v1/device/config')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertSame([$latte, $cake, $burger], $products->keys()->sort()->values()->all());
        $this->assertSame(['product_type' => 'standard', 'sold_in_store' => false, 'sold_on_delivery' => true, 'sold_out' => false],
            array_intersect_key($products[$burger], array_flip(['sold_in_store', 'sold_on_delivery', 'sold_out', 'product_type'])));
        $this->assertSame([
            ['provider_id' => $providers[0], 'price_baisas' => 2200, 'listed' => true],
            ['provider_id' => $providers[1], 'price_baisas' => 2500, 'listed' => false],
        ], $products[$burger]['delivery_prices']);
        $this->assertSame([$global], $products[$latte]['addon_group_ids']);

        // A delta purges what stopped being sellable here.
        $since = now();
        $this->travel(1)->minutes();
        DB::table('pos_products')->where('id', $latte)->update(['status' => 'inactive', 'updated_at' => now()]);
        DB::table('pos_products')->where('id', $cake)->update(['branch_scope' => 'all', 'updated_at' => now()]);
        DB::table('pos_branch_product')->where('product_id', $cake)->update(['is_available' => false, 'updated_at' => now()]);
        $delta = $this->withToken('mdev_p4_channels')->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame([], $delta['products']);
        $this->assertEqualsCanonicalizing([$latte, $cake], $delta['deleted']['products']);
        $this->assertNotContains($old, $delta['deleted']['products']);
    }

    public function test_qr_offers_only_in_store_products_and_leaves_out_inactive_ones(): void
    {
        $session = $this->p4QrSession();
        $latte = $this->p4Product('Latte');
        $this->branchRow($latte, 11, true, '4.000');
        $deliveryOnly = $this->p4Product('Family box', '9.000', ['sold_in_store' => false]);
        $inactive = $this->p4Product('Old', '1.000', ['status' => 'inactive']);

        $ids = array_column($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'), 'id');
        $this->assertSame([$latte], $ids);
        $this->assertNotContains($deliveryOnly, $ids);
        $this->assertNotContains($inactive, $ids);

        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [['product_id' => $deliveryOnly, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [['product_id' => $latte, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]])
            ->assertOk();
    }

    public function test_kitchen_batches_follow_the_branch_scope_and_their_shelf_rows_never_restrict(): void
    {
        $this->seedPosStaff([7]);
        $device = Device::factory()->paired('mdev_p4_kitchen')->create(['company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos']);
        $platter = $this->p4Product('Platter', '3.000', ['stock_mode' => 'cooked']);
        $this->branchRow($platter, 11, true, '6.000');                     // cooked at branch 11 first

        $production = app(StartProductionAction::class)->handle($device, $platter, 2, null, []);
        app(FinishProductionAction::class)->handle($device, (string) $production->uuid, null);
        $row = DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', $platter)->first();
        $this->assertSame([true, 2.0], [(bool) $row->is_available, (float) $row->stock_qty]);
        $this->assertContains($platter, array_column($this->withToken('mdev_p4_kitchen')->getJson('/api/v1/device/kitchen')->assertOk()->json('data.products'), 'id'));

        // A 'selected' product not selected here cannot be cooked here.
        $selected = $this->p4Product('Lasagne', '3.000', ['stock_mode' => 'cooked', 'branch_scope' => 'selected']);
        $this->branchRow($selected, 11, true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available at your branch');
        app(StartProductionAction::class)->handle($device, $selected, 1, null, []);
    }
}
