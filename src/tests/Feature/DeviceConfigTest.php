<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8.1 — device config bundle (GET /api/v1/device/config[/delta]).
 *
 * Catalogue is seeded for company 100 / branch 10 (the device's), with
 * deliberate noise from a second branch (11, same company) and a second
 * company (200) to prove tenant + branch scoping.
 */
class DeviceConfigTest extends TestCase
{
    use RefreshDatabase;

    private string $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = now()->subDay()->toDateTimeString();
    }

    private function pairedDevice(): Device
    {
        return Device::factory()->paired('mdev_cfg')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedCatalogue(): void
    {
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        DB::table('pos_branches')->insert([
            ['id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main', 'latitude' => 23.5, 'longitude' => 58.4, 'geofence_radius_m' => 300, 'default_order_type' => 'dine_in', 'status' => 'active'] + $t,
            ['id' => 11, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Second', 'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500, 'default_order_type' => 'dine_in', 'status' => 'active'] + $t,
            ['id' => 20, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCo', 'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500, 'default_order_type' => 'dine_in', 'status' => 'active'] + $t,
        ]);

        DB::table('pos_floors')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Ground', 'display_order' => 1, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 11, 'name' => 'OtherBranch', 'display_order' => 1, 'status' => 'active'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'branch_id' => 20, 'name' => 'OtherCoFloor', 'display_order' => 1, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_tables')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T1', 'seats' => 4, 'shape' => 'square', 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 2, 'label' => 'T2', 'seats' => 4, 'shape' => 'square', 'status' => 'active'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'floor_id' => 3, 'label' => 'T3', 'seats' => 4, 'shape' => 'square', 'status' => 'active'] + $t,
        ]);

        DB::table('pos_product_categories')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Drinks', 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoCat', 'status' => 'active'] + $t,
        ]);

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => 1, 'name' => 'Latte', 'base_price' => 1.500, 'stock_mode' => 'ingredient', 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => 1, 'name' => 'Tea', 'base_price' => 0.800, 'stock_mode' => 'unit', 'status' => 'active'] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'category_id' => null, 'name' => 'OtherCoProd', 'base_price' => 5.000, 'stock_mode' => 'untracked', 'status' => 'active'] + $t,
        ]);

        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => 1, 'quantity' => 0.250, 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);

        DB::table('pos_addon_groups')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'selection_mode' => 'single', 'is_global' => false, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoGroup', 'selection_mode' => 'single', 'is_global' => false, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_addons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Oat', 'price_delta' => 0.500, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'add_on_group_id' => 2, 'name' => 'OtherCoAddon', 'price_delta' => 1.000, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_addon_group_products')->insert([
            ['add_on_group_id' => 1, 'product_id' => 1, 'display_order' => 0] + $t,
        ]);

        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'l', 'default_unit_cost' => 0.400, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoIng', 'unit' => 'kg', 'default_unit_cost' => 1.000, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => 5.000] + $t,
            ['branch_id' => 11, 'ingredient_id' => 1, 'quantity' => 9.000] + $t,
            ['branch_id' => 20, 'ingredient_id' => 2, 'quantity' => 7.000] + $t,
        ]);

        // P-F4 auto_apply: order-scope rule 1 stays manual (false); the
        // product-scope rule 2 carries true per the backfill/write-action
        // invariant (targeted rules are always automatic on the device).
        DB::table('pos_discounts')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'OMR1 off', 'scope' => 'order', 'amount_type' => 'fixed', 'amount' => 1.000, 'stackable' => false, 'requires_manager_approval' => false, 'auto_apply' => false, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => '10% off', 'scope' => 'product', 'amount_type' => 'percent', 'amount' => 10.000, 'stackable' => false, 'requires_manager_approval' => false, 'auto_apply' => true, 'status' => 'active'] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoDisc', 'scope' => 'order', 'amount_type' => 'fixed', 'amount' => 2.000, 'stackable' => false, 'requires_manager_approval' => false, 'auto_apply' => false, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_discount_targets')->insert([
            ['discount_id' => 2, 'target_type' => 'product', 'target_id' => 1] + $t,
        ]);

        DB::table('pos_loyalty_rules')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Coffee card', 'type' => 'visit_based', 'config_json' => json_encode(['stamps_required' => 10]), 'status' => 'active'] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoRule', 'type' => 'spend_based', 'config_json' => null, 'status' => 'active'] + $t,
        ]);

        DB::table('pos_customers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ali', 'phone' => '+96890000000', 'wallet_balance' => 3.000] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoCust', 'phone' => '+96899999999', 'wallet_balance' => 1.000] + $t,
        ]);

        // Customer 1 holds a loyalty balance under rule 1 (for the offline cache).
        DB::table('pos_loyalty_accounts')->insert([
            ['uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => 1, 'loyalty_rule_id' => 1, 'point_balance' => 50, 'stamp_count' => 3] + $t,
        ]);

        // P-F2 — customer 1 also holds a vehicle plate (offline drive-thru
        // lookup), plus an other-company plate to prove it never leaks.
        DB::table('pos_customer_vehicle_plates')->insert([
            ['uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => 1, 'plate_number' => '12345 A'] + $t,
            ['uuid' => (string) Str::uuid(), 'company_id' => 200, 'customer_id' => 99, 'plate_number' => '99999 Z'] + $t,
        ]);

        DB::table('pos_taxes')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'VAT', 'rate_percent' => 5.00, 'is_active' => true, 'sort_order' => 1] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Municipality', 'rate_percent' => 2.00, 'is_active' => true, 'sort_order' => 2] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Inactive', 'rate_percent' => 9.00, 'is_active' => false, 'sort_order' => 3] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoTax', 'rate_percent' => 7.00, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);

        // v2 #7 — custom expense categories: 2 active + 1 inactive for company
        // 100, 1 for company 200 (isolation), in sort order.
        DB::table('pos_expense_categories')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Utilities', 'name_ar' => null, 'key' => 'utilities', 'is_active' => true, 'sort_order' => 1] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Marketing', 'name_ar' => null, 'key' => 'marketing', 'is_active' => true, 'sort_order' => 2] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Retired', 'name_ar' => null, 'key' => 'retired', 'is_active' => false, 'sort_order' => 3] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoCat', 'name_ar' => null, 'key' => 'otherco', 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
    }

    public function test_full_config_includes_delivery_providers_and_per_product_prices(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        DB::table('pos_delivery_providers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat', 'color' => '#FF5A00', 'is_active' => true, 'sort_order' => 1] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Otlob', 'color' => null, 'is_active' => true, 'sort_order' => 2] + $t,
            // Inactive + another company — both excluded from the picker.
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Paused', 'color' => null, 'is_active' => false, 'sort_order' => 3] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoProvider', 'color' => null, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
        // Soft-deleted provider (separate insert — carries the extra deleted_at column).
        DB::table('pos_delivery_providers')->insert(
            ['id' => 4, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Gone', 'color' => null, 'is_active' => true, 'sort_order' => 4, 'deleted_at' => $this->old] + $t,
        );

        DB::table('pos_product_delivery_prices')->insert([
            // Latte (1) is 2.000 on Talabat (1) — overrides its 1.500 base.
            ['product_id' => 1, 'delivery_provider_id' => 1, 'company_id' => 100, 'price' => 2.000] + $t,
        ]);

        $res = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk();
        $data = $res->json('data');

        // Only company 100's ACTIVE, non-deleted providers, in sort order.
        $this->assertCount(2, $data['delivery_providers']);
        $this->assertSame([1, 2], collect($data['delivery_providers'])->pluck('id')->all());
        $this->assertSame('Talabat', $data['delivery_providers'][0]['name']);
        $this->assertSame('#FF5A00', $data['delivery_providers'][0]['color']);

        // Latte carries the Talabat override (baisas); Tea has none.
        $latte = collect($data['products'])->firstWhere('id', 1);
        $tea = collect($data['products'])->firstWhere('id', 2);
        $this->assertCount(1, $latte['delivery_prices']);
        $this->assertSame(1, $latte['delivery_prices'][0]['provider_id']);
        $this->assertSame(2000, $latte['delivery_prices'][0]['price_baisas']);
        $this->assertSame([], $tea['delivery_prices']);
    }

    public function test_config_emits_the_branch_receipt_template(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        $logo = base64_encode("\x89PNG\r\n\x1a\nfake-png");
        DB::table('pos_branches')->where('id', 10)->update([
            'receipt_template' => json_encode([
                'business_name' => 'Aroma Cafe',
                'cr_number' => 'CR-12345',
                'vat_number' => 'OM100200300',
                'footer_lines' => ['Thank you'],
                'show_qr' => true,
                'logo_base64' => $logo,
            ]),
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame('Aroma Cafe', $data['branch']['receipt_template']['business_name']);
        $this->assertSame('CR-12345', $data['branch']['receipt_template']['cr_number']);
        $this->assertSame('OM100200300', $data['branch']['receipt_template']['vat_number']);
        $this->assertSame(['Thank you'], $data['branch']['receipt_template']['footer_lines']);
        $this->assertTrue($data['branch']['receipt_template']['show_qr']);
        // The logo rides inside the same JSON map — no pos_api change needed.
        $this->assertSame($logo, $data['branch']['receipt_template']['logo_base64']);
    }

    /**
     * Phase C3 — meta.websocket tells the device where to dial Reverb. Null
     * under the test suite's null broadcaster (and any un-configured install);
     * populated when the reverb connection is active with an app key. A null
     * host means "reuse the API host you already talk to".
     */
    public function test_meta_websocket_reflects_the_reverb_configuration(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        // phpunit.xml pins the null broadcaster — no websocket block.
        $this->assertNull(
            $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->json('meta.websocket'),
        );

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'app-key-1',
            'broadcasting.connections.reverb.public.host' => null,
            'broadcasting.connections.reverb.public.port' => 8085,
            'broadcasting.connections.reverb.public.scheme' => 'http',
        ]);

        $ws = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->json('meta.websocket');
        $this->assertSame(
            ['app_key' => 'app-key-1', 'host' => null, 'port' => 8085, 'scheme' => 'http'],
            $ws,
        );
    }

    public function test_full_config_returns_the_scoped_catalogue(): void
    {
        $this->seedCatalogue();
        $device = $this->pairedDevice();

        $res = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk();
        $data = $res->json('data');

        // Meta
        $this->assertSame('full', $res->json('meta.mode'));
        $this->assertSame('baisas', $res->json('meta.money_unit'));
        $this->assertSame(100, $res->json('meta.company_id'));
        $this->assertSame(10, $res->json('meta.branch_id'));

        // Branch + structure (branch 11 / company 200 excluded)
        $this->assertSame(10, $data['branch']['id']);
        $this->assertSame(300, $data['branch']['geofence_radius_m']);
        $this->assertCount(1, $data['floors']);
        $this->assertSame(1, $data['floors'][0]['id']);
        $this->assertCount(1, $data['tables']);
        $this->assertSame(1, $data['tables'][0]['id']);

        // Catalogue
        $this->assertCount(1, $data['categories']);
        $this->assertCount(2, $data['products']);
        $this->assertEqualsCanonicalizing([1, 2], collect($data['products'])->pluck('id')->all());

        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertSame(1500, $latte['base_price_baisas']);
        $this->assertSame('ingredient', $latte['stock_mode']);
        $this->assertSame('unit', collect($data['products'])->firstWhere('id', 2)['stock_mode']);
        $this->assertSame([1], $latte['addon_group_ids']);
        $this->assertCount(1, $latte['recipe']);
        $this->assertSame(1, $latte['recipe'][0]['ingredient_id']);
        $this->assertSame(0.25, $latte['recipe'][0]['quantity']);
        $this->assertSame('l', $latte['recipe'][0]['unit']);

        // Add-ons
        $this->assertCount(1, $data['addon_groups']);
        $this->assertSame(1, $data['addon_groups'][0]['id']);
        $this->assertCount(1, $data['addon_groups'][0]['addons']);
        $this->assertSame(500, $data['addon_groups'][0]['addons'][0]['price_delta_baisas']);

        // Ingredients + branch stock (only branch 10's)
        $this->assertCount(1, $data['ingredients']);
        $this->assertCount(1, $data['branch_stock']);
        $this->assertSame(1, $data['branch_stock'][0]['ingredient_id']);
        $this->assertEquals(5.0, $data['branch_stock'][0]['quantity']);

        // Discounts: fixed vs percent normalization + targets
        $this->assertCount(2, $data['discounts']);
        $fixed = collect($data['discounts'])->firstWhere('id', 1);
        $percent = collect($data['discounts'])->firstWhere('id', 2);
        $this->assertSame(1000, $fixed['amount_baisas']);
        $this->assertNull($fixed['percent']);
        $this->assertNull($percent['amount_baisas']);
        $this->assertEquals(10.0, $percent['percent']);
        $this->assertCount(1, $percent['targets']);
        $this->assertSame(1, $percent['targets'][0]['target_id']);
        // P-F4 auto_apply: emitted as a strict bool so the device can cache
        // it. Order-scope rule 1 = manual picker (false); product-scope
        // rule 2 = true (targeted rules are always automatic — the device
        // ignores the flag for them but the value still mirrors storage).
        $this->assertFalse($fixed['auto_apply']);
        $this->assertTrue($percent['auto_apply']);

        // Loyalty + customers
        $this->assertCount(1, $data['loyalty_rules']);
        $this->assertSame(10, $data['loyalty_rules'][0]['config']['stamps_required']);
        $this->assertCount(1, $data['customers']);
        $this->assertSame(3000, $data['customers'][0]['wallet_balance_baisas']);
        // P-F2 — cached vehicle plates (uppercased strings) for the offline
        // drive-thru lookup; the other company's plate must not leak in.
        $this->assertSame(['12345 A'], $data['customers'][0]['plates']);
        // Cached loyalty balances per rule (for offline view/redeem).
        $this->assertSame(
            [['rule_id' => 1, 'points' => 50, 'stamps' => 3]],
            $data['customers'][0]['loyalty'],
        );

        // Taxes: only active company-100 taxes (inactive + company-200 excluded)
        $this->assertCount(2, $data['taxes']);
        $this->assertSame([1, 2], collect($data['taxes'])->pluck('id')->all());
        $vat = collect($data['taxes'])->firstWhere('id', 1);
        $this->assertSame('VAT', $vat['name']);
        $this->assertEquals(5.0, $vat['rate_percent']);

        // Expense categories (v2 #7): only active company-100, in sort order;
        // inactive + company-200 excluded. The device submits `key` back.
        $this->assertCount(2, $data['expense_categories']);
        $this->assertSame([1, 2], collect($data['expense_categories'])->pluck('id')->all());
        $this->assertSame(['utilities', 'marketing'], collect($data['expense_categories'])->pluck('key')->all());
        $this->assertSame('Utilities', $data['expense_categories'][0]['name']);

        // Nothing from company 200 leaked
        $this->assertNotContains(99, collect($data['products'])->pluck('id')->all());
        $this->assertNotContains('otherco', collect($data['expense_categories'])->pluck('key')->all());
    }

    public function test_full_config_excludes_soft_deleted_rows(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        DB::table('pos_products')->where('id', 2)->update(['deleted_at' => now()->toDateTimeString()]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertCount(1, $data['products']);
        $this->assertSame(1, $data['products'][0]['id']);
    }

    public function test_config_requires_a_device_token(): void
    {
        $this->seedCatalogue();
        $this->getJson('/api/v1/device/config')->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_unassigned')->getJson('/api/v1/device/config')->assertStatus(409);
    }

    public function test_delta_requires_a_since_timestamp(): void
    {
        $this->pairedDevice();

        $this->withToken('mdev_cfg')->getJson('/api/v1/device/config/delta')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_delta_returns_only_changed_rows_and_deleted_ids(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        $since = now()->subHours(2);
        $now = now()->toDateTimeString();

        // Product 1 edited after `since`.
        DB::table('pos_products')->where('id', 1)->update(['updated_at' => $now]);
        // Product 2 soft-deleted after `since`.
        DB::table('pos_products')->where('id', 2)->update(['deleted_at' => $now, 'updated_at' => $now]);
        // A brand-new customer after `since`.
        DB::table('pos_customers')->insert([
            'id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sara', 'phone' => '+96890000001',
            'wallet_balance' => 4.000, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $res = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk();
        $data = $res->json('data');

        $this->assertSame('delta', $res->json('meta.mode'));
        $this->assertNotNull($res->json('meta.since'));

        // Only the edited product is "changed"; the deleted one is excluded.
        $this->assertSame([1], collect($data['products'])->pluck('id')->all());
        // The deleted product surfaces in the purge list.
        $this->assertContains(2, $data['deleted']['products']);

        // Only the new customer is changed.
        $this->assertSame([2], collect($data['customers'])->pluck('id')->all());

        // Untouched entities are empty; the branch (unchanged) is null.
        $this->assertCount(0, $data['categories']);
        $this->assertNull($data['branch']);
        $this->assertSame([], $data['deleted']['categories']);
    }

    public function test_products_are_filtered_to_the_devices_branch(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // branch 10
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Tea (id 2) is assigned to branch 11 ONLY -> hidden from branch 10.
        // Latte (id 1) stays unassigned -> available everywhere (incl. 10).
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 11, 'product_id' => 2, 'is_available' => true, 'stock_qty' => null] + $t,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame([1], collect($data['products'])->pluck('id')->all());
    }

    public function test_a_product_marked_unavailable_at_the_branch_is_hidden(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Latte disabled at branch 10; Tea enabled at branch 10.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => false, 'stock_qty' => null] + $t,
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => null] + $t,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame([2], collect($data['products'])->pluck('id')->all());
    }

    public function test_per_branch_product_unit_stock_is_returned(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Latte unit-tracked at branch 10 with 20 units; Tea left unassigned.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => 20.000] + $t,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $latte = collect($data['products'])->firstWhere('id', 1);
        $tea = collect($data['products'])->firstWhere('id', 2);
        $this->assertEquals(20.0, $latte['branch_stock_qty']);
        $this->assertNull($tea['branch_stock_qty']);
    }

    /**
     * Regression — a 2nd kitchen production batch (or any per-branch shelf
     * move: sale decrement, restock, availability toggle) bumps ONLY
     * pos_branch_product.stock_qty, NOT pos_products.updated_at. The delta must
     * still re-emit the product with its fresh branch_stock_qty, otherwise the
     * device keeps a stale sellable cap — e.g. stuck at the first batch of 5
     * while the kitchen shelf already reads 10. Mirrors the pivot-only write
     * paths in FinishProductionAction / ConsumeInventoryAction::moveProductStock.
     */
    public function test_delta_re_emits_a_product_when_only_its_branch_shelf_changed(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // branch 10
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Latte (1) shelved at 5 units; Tea (2) at 7 — both stamped OLD so
        // neither is "changed" yet, and pos_products.updated_at stays OLD too.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => 5.000] + $t,
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => 7.000] + $t,
        ]);

        $since = now()->subHours(2);          // after the OLD seed stamp
        $now = now()->toDateTimeString();

        // 2nd batch: bump ONLY Latte's shelf 5 -> 10 on the pivot. Critically
        // leave pos_products.updated_at untouched, as the real finish path does.
        DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', 1)
            ->update(['stock_qty' => 10.000, 'updated_at' => $now]);

        $data = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()->json('data');

        // Latte is re-emitted with the LIVE shelf (10); Tea (pivot + product
        // both unchanged) is NOT in the changed set.
        $this->assertSame([1], collect($data['products'])->pluck('id')->all());
        $this->assertEquals(10.0, collect($data['products'])->firstWhere('id', 1)['branch_stock_qty']);

        // No infinite re-emit: once the cursor advances past the pivot bump,
        // the product drops back out of the delta.
        $after = now()->addSecond();
        $data2 = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($after->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame([], collect($data2['products'])->pluck('id')->all());
    }

    /**
     * Regression — disabling a product at the branch (pos_branch_product
     * is_available flipped true→false, a pivot-only write via the merchant's
     * SyncProductBranchesAction) must PURGE it from a delta device. The flip is
     * not a soft-delete and not an is_internal change, so it never reaches the
     * `changed` products set, and the payload carries no per-branch availability
     * field for the device to filter on locally — without the deleted.products
     * purge a tile already cached on the device would keep selling until the
     * next full re-sync. Symmetric to the shelf-qty re-emit above.
     */
    public function test_delta_purges_a_product_disabled_at_the_branch(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // branch 10
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Latte (1) + Tea (2) both available at branch 10, stamped OLD so the
        // device has already cached them and neither is "changed" yet.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => null] + $t,
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => null] + $t,
        ]);

        $since = now()->subHours(2);          // after the OLD seed stamp
        $now = now()->toDateTimeString();

        // Disable Latte at branch 10 — a pivot-only write: is_available
        // true→false, pos_products.updated_at left untouched (as the merchant
        // SyncProductBranchesAction does).
        DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', 1)
            ->update(['is_available' => false, 'updated_at' => $now]);

        $data = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()->json('data');

        // Latte is purged via the delta's deleted map, NOT re-emitted in the
        // (filtered-out) changed products set. Tea is untouched either way.
        $this->assertContains(1, $data['deleted']['products']);
        $this->assertNotContains(1, collect($data['products'])->pluck('id')->all());

        // No infinite purge: once the cursor advances past the pivot flip, the
        // product drops out of the deleted map too.
        $after = now()->addSecond();
        $data2 = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($after->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertNotContains(1, $data2['deleted']['products']);
    }

    public function test_settings_defaults_order_cancel_positions_to_manager(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100, no policy row configured

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['order_cancel_positions']);
    }

    public function test_settings_reflects_the_merchant_order_cancel_policy(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_cancel_positions',
            'value' => json_encode(['manager', 'supervisor']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager', 'supervisor'], $data['settings']['order_cancel_positions']);
    }

    public function test_settings_policy_is_scoped_to_the_devices_company(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100
        // A policy for ANOTHER company must not leak into this device's config.
        DB::table('pos_company_settings')->insert([
            'company_id' => 200,
            'key' => 'order_cancel_positions',
            'value' => json_encode(['cashier']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['order_cancel_positions']);
    }

    public function test_settings_defaults_manager_approval_positions_to_manager(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100, no policy row configured

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['manager_approval_positions']);
    }

    public function test_settings_reflects_the_merchant_manager_approval_policy_in_full_and_delta(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'manager_approval_positions',
            'value' => json_encode(['manager', 'supervisor']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        // FULL config carries the policy…
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertSame(['manager', 'supervisor'], $data['settings']['manager_approval_positions']);

        // …and so does every DELTA (settings are always emitted, not
        // delta-tracked) — even when nothing else changed since.
        $since = now()->subMinute();
        $delta = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()
            ->json('data');
        $this->assertSame(['manager', 'supervisor'], $delta['settings']['manager_approval_positions']);
        $this->assertSame(['manager'], $delta['settings']['order_cancel_positions']);
    }

    public function test_settings_manager_approval_policy_is_scoped_to_the_devices_company(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100
        DB::table('pos_company_settings')->insert([
            'company_id' => 200,
            'key' => 'manager_approval_positions',
            'value' => json_encode(['cashier']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['manager_approval_positions']);
    }

    public function test_settings_defaults_reports_positions_to_manager(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100, no policy row configured

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['reports_positions']);
    }

    public function test_settings_reflects_the_merchant_reports_positions_policy_in_full_and_delta(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'reports_positions',
            'value' => json_encode(['manager', 'supervisor']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        // FULL config carries the policy…
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertSame(['manager', 'supervisor'], $data['settings']['reports_positions']);

        // …and so does every DELTA (settings are always emitted, not
        // delta-tracked) — even when nothing else changed since.
        $since = now()->subMinute();
        $delta = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()
            ->json('data');
        $this->assertSame(['manager', 'supervisor'], $delta['settings']['reports_positions']);
        $this->assertSame(['manager'], $delta['settings']['manager_approval_positions']);
    }

    public function test_settings_reports_positions_policy_is_scoped_to_the_devices_company(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100
        DB::table('pos_company_settings')->insert([
            'company_id' => 200,
            'key' => 'reports_positions',
            'value' => json_encode(['cashier']),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame(['manager'], $data['settings']['reports_positions']);
    }

    public function test_settings_defaults_order_numbering_to_disabled(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100, no policy row configured

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        // P-F8 — always the full normalised five-key shape.
        $this->assertSame([
            'enabled' => false,
            'prefix' => '',
            'pad' => 4,
            'scope' => 'branch',
            'daily_reset' => false,
        ], $data['settings']['order_numbering']);
    }

    public function test_settings_reflects_the_merchant_order_numbering_policy_in_full_and_delta(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true,
                'prefix' => 'KLD-',
                'pad' => 4,
                'scope' => 'company',
                'daily_reset' => true,
            ]),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $expected = [
            'enabled' => true,
            'prefix' => 'KLD-',
            'pad' => 4,
            'scope' => 'company',
            'daily_reset' => true,
        ];

        // FULL config carries the policy…
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertSame($expected, $data['settings']['order_numbering']);

        // …and so does every DELTA (settings are always emitted, not
        // delta-tracked) — even when nothing else changed since.
        $since = now()->subMinute();
        $delta = $this->withToken('mdev_cfg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()
            ->json('data');
        $this->assertSame($expected, $delta['settings']['order_numbering']);
    }

    public function test_settings_order_numbering_policy_is_scoped_to_the_devices_company(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // company 100
        DB::table('pos_company_settings')->insert([
            'company_id' => 200,
            'key' => 'order_numbering',
            'value' => json_encode(['enabled' => true, 'prefix' => 'X-', 'pad' => 5, 'scope' => 'company', 'daily_reset' => false]),
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertFalse($data['settings']['order_numbering']['enabled']);
    }

    // =================== PHASE D2 — catalogue flags ===================

    public function test_phase_d2_catalogue_flags_default_correctly(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        // Products: tax-exclusive, tablet-visible, no badge by default.
        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertFalse($latte['tax_inclusive']);
        $this->assertTrue($latte['show_on_customer_tablet']);
        $this->assertNull($latte['low_stock_threshold']);
        $this->assertFalse($latte['low_stock']);

        // Categories: null = available at every branch.
        $this->assertNull($data['categories'][0]['branch_ids']);
    }

    public function test_phase_d2_product_flags_are_emitted_when_set(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        DB::table('pos_products')->where('id', 1)->update([
            'tax_inclusive' => true,
            'show_on_customer_tablet' => false,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertTrue($latte['tax_inclusive']);
        $this->assertFalse($latte['show_on_customer_tablet']);
        // tax_inclusive is informational — money mapping is unchanged.
        $this->assertSame(1500, $latte['base_price_baisas']);
    }

    // =================== G1 — menu time-window ===================

    public function test_availability_window_defaults_to_null_always_available(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertArrayHasKey('available_from', $latte);
        $this->assertArrayHasKey('available_until', $latte);
        $this->assertNull($latte['available_from']);
        $this->assertNull($latte['available_until']);
    }

    public function test_availability_window_is_emitted_verbatim_when_set(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        DB::table('pos_products')->where('id', 1)->update([
            'available_from' => '06:00:00',
            'available_until' => '11:00:00',
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        // Raw 'HH:MM:SS' string pass-through — the DEVICE evaluates the
        // window against its local clock (the discount-window convention;
        // start > end wraps midnight).
        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertSame('06:00:00', $latte['available_from']);
        $this->assertSame('11:00:00', $latte['available_until']);
    }

    /**
     * A category narrowed to ANOTHER branch is still emitted (with its
     * branch_ids) — the DEVICE hides it from the strip. Server-side
     * filtering would strand it forever on delta devices, because a
     * category dropped from a branch is not soft-deleted and never
     * surfaces in the `deleted` purge map.
     */
    public function test_category_branch_ids_are_emitted_but_never_server_filtered(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice(); // branch 10

        DB::table('pos_product_categories')->where('id', 1)->update([
            'branch_availability_json' => json_encode([11]),
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertCount(1, $data['categories']);
        $this->assertSame(1, $data['categories'][0]['id']);
        $this->assertSame([11], $data['categories'][0]['branch_ids']);
    }

    public function test_unit_mode_low_stock_compares_branch_stock_to_the_threshold(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // Tea (id 2) is unit-mode with a threshold of 5 and 4 units left
        // at branch 10 → LOW. Branch 11 holds plenty — must not bleed in.
        DB::table('pos_products')->where('id', 2)->update(['low_stock_threshold' => 5.000]);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => 4.000] + $t,
            ['branch_id' => 11, 'product_id' => 2, 'is_available' => true, 'stock_qty' => 100.000] + $t,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $tea = collect($data['products'])->firstWhere('id', 2);
        $this->assertTrue($tea['low_stock']);
        $this->assertEquals(5.0, $tea['low_stock_threshold']);

        // Above the threshold → not low.
        DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', 2)->update(['stock_qty' => 6.000]);
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertFalse(collect($data['products'])->firstWhere('id', 2)['low_stock']);

        // Sold out (0) is the stronger state — not "low".
        DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', 2)->update(['stock_qty' => 0.000]);
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertFalse(collect($data['products'])->firstWhere('id', 2)['low_stock']);
    }

    public function test_unit_mode_low_stock_is_false_without_a_threshold(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        // 1 unit left but NO threshold configured → no badge.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => 1.000] + $t,
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertFalse(collect($data['products'])->firstWhere('id', 2)['low_stock']);
    }

    public function test_ingredient_mode_low_stock_derives_from_ingredient_min_thresholds(): void
    {
        $this->seedCatalogue();
        $this->pairedDevice();

        // Latte (id 1, ingredient-mode) uses ingredient 1; branch 10 holds
        // 5.000 of it. A min_stock_threshold of 6 puts it below → LOW.
        DB::table('pos_ingredients')->where('id', 1)->update(['min_stock_threshold' => 6.000]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $latte = collect($data['products'])->firstWhere('id', 1);
        $this->assertTrue($latte['low_stock']);

        // Balance (5.000) at/above the minimum (4.000) → not low.
        DB::table('pos_ingredients')->where('id', 1)->update(['min_stock_threshold' => 4.000]);
        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $this->assertFalse(collect($data['products'])->firstWhere('id', 1)['low_stock']);
    }
}
