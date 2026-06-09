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

        DB::table('pos_discounts')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'OMR1 off', 'scope' => 'order', 'amount_type' => 'fixed', 'amount' => 1.000, 'stackable' => false, 'requires_manager_approval' => false, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => '10% off', 'scope' => 'product', 'amount_type' => 'percent', 'amount' => 10.000, 'stackable' => false, 'requires_manager_approval' => false, 'status' => 'active'] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCoDisc', 'scope' => 'order', 'amount_type' => 'fixed', 'amount' => 2.000, 'stackable' => false, 'requires_manager_approval' => false, 'status' => 'active'] + $t,
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

        DB::table('pos_branches')->where('id', 10)->update([
            'receipt_template' => json_encode([
                'business_name' => 'Aroma Cafe',
                'cr_number' => 'CR-12345',
                'vat_number' => 'OM100200300',
                'footer_lines' => ['Thank you'],
                'show_qr' => true,
            ]),
        ]);

        $data = $this->withToken('mdev_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $this->assertSame('Aroma Cafe', $data['branch']['receipt_template']['business_name']);
        $this->assertSame('CR-12345', $data['branch']['receipt_template']['cr_number']);
        $this->assertSame('OM100200300', $data['branch']['receipt_template']['vat_number']);
        $this->assertSame(['Thank you'], $data['branch']['receipt_template']['footer_lines']);
        $this->assertTrue($data['branch']['receipt_template']['show_qr']);
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

        // Loyalty + customers
        $this->assertCount(1, $data['loyalty_rules']);
        $this->assertSame(10, $data['loyalty_rules'][0]['config']['stamps_required']);
        $this->assertCount(1, $data['customers']);
        $this->assertSame(3000, $data['customers'][0]['wallet_balance_baisas']);
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
}
