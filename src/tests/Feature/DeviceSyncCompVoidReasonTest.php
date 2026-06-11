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
 * Phase B (Additions §1.2) — comps on order.create + reasoned voids
 * on order.void + the new config slices.
 *
 * Comps: always carry a valid company comp reason, capped by its
 * max_amount, summing exactly to comp_total_baisas; the money
 * invariant becomes subtotal − discount − comp + tax == grand_total.
 *
 * Voids: void_reason_id resolves tenant-scoped and snapshots onto
 * the order; affects_inventory=TRUE (food was made) KEEPS the
 * recipe consumption (no inventory reverse); FALSE restores stock
 * (legacy behaviour, also the default with no reason).
 */
class DeviceSyncCompVoidReasonTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_cv', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedCatalogue(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'l', 'default_unit_cost' => 0.400, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => 1, 'quantity' => 0.250, 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => 5.000] + $t,
        ]);
    }

    private function seedReasons(int $company = 100): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_void_reasons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'code' => 'quality_issue', 'name' => 'Quality Issue', 'affects_inventory' => true, 'requires_manager' => true, 'is_active' => true, 'sort_order' => 0] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'code' => 'wrong_order_entry', 'name' => 'Wrong Order Entry', 'affects_inventory' => false, 'requires_manager' => false, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_comp_reasons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'code' => 'long_wait', 'name' => 'Long Wait', 'max_amount' => 2.000, 'is_active' => true, 'sort_order' => 0] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'code' => 'staff_meal', 'name' => 'Staff Meal', 'max_amount' => null, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid, array $orderOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $orderUuid,
                'order_type' => 'dine_in',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->subHour()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 2,
                    'unit_price_baisas' => 1500,
                    'line_discount_baisas' => 0,
                    'line_total_baisas' => 3000,
                ]],
            ], $orderOverrides)],
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function voidEvent(string $orderUuid, array $payloadOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge([
                'order_uuid' => $orderUuid,
                'voided_at' => now()->toIso8601String(),
            ], $payloadOverrides),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, int $amountBaisas = 3000): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subMinutes(30)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subMinutes(30)->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => $amountBaisas, 'change_given_baisas' => 0]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    // =================== COMPS ===================

    public function test_order_create_persists_comps_and_caches_comp_total(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        // One line comped (1.500) under Staff Meal: 3.000 − 1.500 = 1.500 due.
        $uuid = (string) Str::uuid();
        $res = $this->push('mdev_cv', [$this->createEvent($uuid, [
            'comp_total_baisas' => 1500,
            'grand_total_baisas' => 1500,
            'comps' => [[
                'comp_reason_id' => 2,
                'amount_baisas' => 1500,
                'line_index' => 0,
                'staff_id' => 9,
                'note' => 'regular customer',
            ]],
        ])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(1, $res->json('data.results.0.result.comps'));
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'comp_total' => '1.500', 'grand_total' => '1.500']);
        $this->assertDatabaseHas('pos_order_comps', [
            'comp_reason_id' => 2,
            'reason_code_snapshot' => 'staff_meal',
            'reason_name_snapshot' => 'Staff Meal',
            'amount' => '1.500',
            'approved_by_pos_staff_id' => 9,
        ]);
        // The line comp ties to the created order item.
        $this->assertNotNull(DB::table('pos_order_comps')->value('order_item_id'));
    }

    public function test_comp_exceeding_the_reason_cap_fails_the_event(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        // Long Wait caps at 2.000 OMR; comping 2.500 must fail.
        $res = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 2500,
            'grand_total_baisas' => 500,
            'comps' => [['comp_reason_id' => 1, 'amount_baisas' => 2500]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('cap', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_cross_tenant_comp_reason_fails_the_event(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons(999); // reasons belong to ANOTHER company

        $res = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 1000,
            'grand_total_baisas' => 2000,
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => 1000]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('outside the device tenant', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_order_comps', 0);
    }

    public function test_comp_rows_must_sum_to_comp_total(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        $res = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 500,
            'grand_total_baisas' => 2500,
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => 1000]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('sum', $res->json('data.results.0.result.error'));
    }

    // =================== P-F5 GIFT COMPS ===================

    public function test_gift_comp_persists_with_null_reason_and_is_gift_true(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        // Line 0 (1.500) gifted whole: no reason, is_gift carries it.
        $uuid = (string) Str::uuid();
        $res = $this->push('mdev_cv', [$this->createEvent($uuid, [
            'comp_total_baisas' => 1500,
            'grand_total_baisas' => 1500,
            'comps' => [[
                'is_gift' => true,
                'amount_baisas' => 1500,
                'line_index' => 0,
                'staff_id' => 9,
            ]],
        ])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(1, $res->json('data.results.0.result.comps'));
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'comp_total' => '1.500', 'grand_total' => '1.500']);

        $row = DB::table('pos_order_comps')->first();
        $this->assertNull($row->comp_reason_id);
        $this->assertSame(1, (int) $row->is_gift);
        $this->assertSame('gift', $row->reason_code_snapshot);
        $this->assertSame('Gift', $row->reason_name_snapshot);
        $this->assertEqualsWithDelta(1.500, (float) $row->amount, 1e-9);
        $this->assertNotNull($row->order_item_id);
    }

    public function test_gift_comp_bypasses_every_reason_cap(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        // 2.500 gifted — above the 2.000 Long Wait cap, but gifts carry no
        // reason and therefore no cap.
        $uuid = (string) Str::uuid();
        $res = $this->push('mdev_cv', [$this->createEvent($uuid, [
            'comp_total_baisas' => 2500,
            'grand_total_baisas' => 500,
            'comps' => [['is_gift' => true, 'amount_baisas' => 2500, 'line_index' => 0]],
        ])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertDatabaseHas('pos_order_comps', ['is_gift' => true, 'amount' => '2.500']);
    }

    public function test_gift_comp_must_not_carry_a_comp_reason_id(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        $res = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 1500,
            'grand_total_baisas' => 1500,
            'comps' => [['is_gift' => true, 'comp_reason_id' => 2, 'amount_baisas' => 1500]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('must not carry a comp_reason_id', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_order_comps', 0);
    }

    public function test_reasonless_non_gift_comp_still_rejects(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        $res = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 1000,
            'grand_total_baisas' => 2000,
            'comps' => [['amount_baisas' => 1000]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('requires a comp_reason_id', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_mixed_manager_comp_and_gift_sum_to_comp_total(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        // 1.000 Staff Meal comp + 1.500 gift = 2.500 comp_total on a 3.000
        // order → 0.500 due.
        $uuid = (string) Str::uuid();
        $res = $this->push('mdev_cv', [$this->createEvent($uuid, [
            'comp_total_baisas' => 2500,
            'grand_total_baisas' => 500,
            'comps' => [
                ['comp_reason_id' => 2, 'amount_baisas' => 1000, 'staff_id' => 9],
                ['is_gift' => true, 'amount_baisas' => 1500, 'line_index' => 0],
            ],
        ])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(2, $res->json('data.results.0.result.comps'));
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'comp_total' => '2.500', 'grand_total' => '0.500']);
        $this->assertDatabaseHas('pos_order_comps', ['comp_reason_id' => 2, 'is_gift' => false, 'amount' => '1.000']);
        $this->assertDatabaseHas('pos_order_comps', ['comp_reason_id' => null, 'is_gift' => true, 'amount' => '1.500']);

        // And a WRONG total still fails even when a gift row is in the mix.
        $bad = $this->push('mdev_cv', [$this->createEvent((string) Str::uuid(), [
            'comp_total_baisas' => 2000,
            'grand_total_baisas' => 1000,
            'comps' => [
                ['comp_reason_id' => 2, 'amount_baisas' => 1000],
                ['is_gift' => true, 'amount_baisas' => 1500],
            ],
        ])])->assertOk();
        $this->assertSame('failed', $bad->json('data.results.0.status'));
        $this->assertStringContainsString('sum', $bad->json('data.results.0.result.error'));
    }

    // =================== REASONED VOIDS ===================

    public function test_void_with_food_made_reason_keeps_inventory_consumed(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        $uuid = (string) Str::uuid();
        $this->push('mdev_cv', [$this->createEvent($uuid), $this->payEvent($uuid)])->assertOk();
        // Paid: 2 lattes consumed 0.5 L milk → 4.500 on hand.
        $this->assertSame(4.5, (float) DB::table('pos_branch_stock')->where('ingredient_id', 1)->value('quantity'));

        $res = $this->push('mdev_cv', [$this->voidEvent($uuid, ['void_reason_id' => 1])])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertTrue($r['result']['inventory_kept']);
        $this->assertSame(0, $r['result']['reversed']);
        // Quality Issue = food was made → milk STAYS consumed.
        $this->assertSame(4.5, (float) DB::table('pos_branch_stock')->where('ingredient_id', 1)->value('quantity'));
        $this->assertDatabaseHas('pos_orders', [
            'uuid' => $uuid, 'status' => 'void',
            'void_reason_id' => 1, 'void_reason_label' => 'Quality Issue',
        ]);
    }

    public function test_void_with_never_prepared_reason_restores_inventory(): void
    {
        $this->device();
        $this->seedCatalogue();
        $this->seedReasons();

        $uuid = (string) Str::uuid();
        $this->push('mdev_cv', [$this->createEvent($uuid), $this->payEvent($uuid)])->assertOk();

        $res = $this->push('mdev_cv', [$this->voidEvent($uuid, ['void_reason_id' => 2])])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertFalse($r['result']['inventory_kept']);
        $this->assertGreaterThan(0, $r['result']['reversed']);
        // Wrong Order Entry = never prepared → milk restored to 5.000.
        $this->assertSame(5.0, (float) DB::table('pos_branch_stock')->where('ingredient_id', 1)->value('quantity'));
    }

    public function test_void_with_unknown_or_foreign_reason_fails(): void
    {
        $this->device();
        $this->seedCatalogue();

        $uuid = (string) Str::uuid();
        $this->push('mdev_cv', [$this->createEvent($uuid)])->assertOk();

        $res = $this->push('mdev_cv', [$this->voidEvent($uuid, ['void_reason_id' => 42])])->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('void reason not found', $res->json('data.results.0.result.error'));
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'status' => 'open']);
    }

    // =================== CONFIG SLICES ===================

    public function test_config_emits_reason_lists_and_modifier_constraints(): void
    {
        $this->device();
        $this->seedReasons();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_product_categories')->insert([
            ['id' => 5, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Drinks', 'display_order' => 0, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addon_groups')->insert([
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk Choice', 'selection_mode' => 'single', 'min_selections' => 1, 'max_selections' => 1, 'is_global' => false, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addons')->insert([
            ['id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 3, 'name' => 'Whole Milk', 'price_delta' => 0, 'is_default' => true, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addon_group_categories')->insert([
            ['add_on_group_id' => 3, 'category_id' => 5],
        ]);

        $res = $this->withToken('mdev_cv')->getJson('/api/v1/device/config')->assertOk();

        $voidReasons = collect($res->json('data.void_reasons'));
        $this->assertTrue((bool) $voidReasons->firstWhere('code', 'quality_issue')['affects_inventory']);
        $compReasons = collect($res->json('data.comp_reasons'));
        $this->assertSame(2000, $compReasons->firstWhere('code', 'long_wait')['max_amount_baisas']);
        $this->assertNull($compReasons->firstWhere('code', 'staff_meal')['max_amount_baisas']);

        $group = collect($res->json('data.addon_groups'))->firstWhere('id', 3);
        $this->assertSame(1, $group['min_selections']);
        $this->assertSame(1, $group['max_selections']);
        $this->assertTrue($group['addons'][0]['is_default']);

        $category = collect($res->json('data.categories'))->firstWhere('id', 5);
        $this->assertSame([3], $category['addon_group_ids']);
    }
}
