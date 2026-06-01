<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.3 — order lifecycle processed off the sync pipeline.
 *
 * order.create / order.pay / order.void events pushed through
 * POST /api/v1/device/sync/push are now PROCESSED inline: they write
 * pos_orders, take payment, and move branch stock. Money on the wire is
 * integer baisas. Catalogue is seeded for company 100 / branch 10 — a Latte
 * (recipe: 0.25 L Milk) with an "Extra shot" add-on (1 Shot).
 */
class DeviceSyncOrderTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_ord', int $company = 100, int $branch = 10): Device
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
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Shot', 'unit' => 'shot', 'default_unit_cost' => 0.200, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => 1, 'quantity' => 0.250, 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_addons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Extra shot', 'price_delta' => 0.500, 'ingredient_id' => 2, 'ingredient_qty' => 1.000, 'ingredient_unit' => 'shot', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => 5.000] + $t,
            ['branch_id' => 10, 'ingredient_id' => 2, 'quantity' => 10.000] + $t,
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
            'client_timestamp' => now()->subHours(4)->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $orderUuid,
                'order_type' => 'dine_in',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->subHours(4)->toIso8601String(),
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
                    'addons' => [['add_on_id' => 1, 'price_delta_baisas' => 500]],
                ]],
            ], $orderOverrides)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $payments
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, ?array $payments = null): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subHours(3)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subHours(3)->toIso8601String(),
                'payments' => $payments ?? [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voidEvent(string $orderUuid): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'customer left'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_order_create_persists_order_with_a_recipe_snapshot(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertFalse($r['duplicate']);
        $this->assertSame('processed', $r['status']);
        $this->assertSame('created', $r['result']['status']);

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertNotNull($order);
        $this->assertSame('open', $order->status);
        $this->assertSame(100, (int) $order->company_id);
        $this->assertSame(10, (int) $order->branch_id);
        $this->assertSame('3.000', $order->grand_total);

        $item = OrderItem::firstWhere('order_id', $order->id);
        $this->assertSame(1, $item->recipe_snapshot_json[0]['ingredient_id']);
        $this->assertEqualsWithDelta(0.25, $item->recipe_snapshot_json[0]['qty'], 1e-9);
        $this->assertEqualsWithDelta(0.4, $item->recipe_snapshot_json[0]['unit_cost'], 1e-9);
        $this->assertDatabaseHas('pos_order_item_addons', ['order_item_id' => $item->id, 'add_on_id' => 1]);

        // Inventory deducts at PAY, not create — nothing moved yet.
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_pay_consumes_inventory_and_marks_the_order_paid(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame('paid', $r['result']['status']);
        $this->assertSame(2, $r['result']['movements']); // milk + shot

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertDatabaseCount('pos_payments', 1);

        // Latte ×2 → 0.5 L milk (5→4.5); Extra shot ×2 → 2 shots (10→8).
        $this->assertEqualsWithDelta(4.5, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);
        $this->assertEqualsWithDelta(8.0, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 2])->value('quantity'), 1e-9);
        $this->assertDatabaseCount('pos_stock_movements', 2);
        $this->assertDatabaseHas('pos_stock_movements', ['movement_type' => 'sale_consumption', 'ingredient_id' => 1, 'reference_type' => 'pos_orders', 'reference_id' => $order->id]);
        $this->assertDatabaseHas('pos_stock_movements', ['movement_type' => 'addon_consumption', 'ingredient_id' => 2, 'reference_id' => $order->id]);
    }

    public function test_voiding_a_paid_order_reverses_the_stock(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();

        $res = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        $r = $res->json('data.results.0');
        $this->assertSame('processed', $r['status']);
        $this->assertSame('voided', $r['result']['status']);
        $this->assertSame(2, $r['result']['reversed']);

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame('void', $order->status);
        $this->assertSame('void', OrderItem::firstWhere('order_id', $order->id)->status);

        // Stock fully restored; the order nets to zero inventory impact.
        $this->assertEqualsWithDelta(5.0, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);
        $this->assertEqualsWithDelta(10.0, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 2])->value('quantity'), 1e-9);
        $this->assertDatabaseCount('pos_stock_movements', 4); // 2 consume + 2 reverse
    }

    public function test_replaying_create_and_pay_settles_exactly_once(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $create = $this->createEvent($uuid);
        $pay = $this->payEvent($uuid);

        $this->push('mdev_ord', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_stock_movements', 2);

        // Flaky link: the device re-pushes the identical batch.
        $res = $this->push('mdev_ord', [$create, $pay])->assertOk();
        $res->assertJsonPath('data.summary.accepted', 0)
            ->assertJsonPath('data.summary.duplicates', 2);

        // Nothing settled twice.
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_stock_movements', 2);
        $this->assertEqualsWithDelta(4.5, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);

        foreach ($res->json('data.results') as $ack) {
            $this->assertTrue($ack['duplicate']);
        }
    }

    public function test_split_payment_records_a_row_per_tender(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, [
            ['method' => 'cash', 'amount_baisas' => 2000, 'change_given_baisas' => 0],
            ['method' => 'card', 'amount_baisas' => 1000, 'softpos_reference' => 'TXN1', 'softpos_auth_code' => 'A1'],
        ])])->assertOk();

        $this->assertSame('paid', Order::firstWhere('uuid', $uuid)->status);
        $this->assertDatabaseCount('pos_payments', 2);
        $this->assertDatabaseHas('pos_payments', ['method' => 'cash']);
        $this->assertDatabaseHas('pos_payments', ['method' => 'card', 'softpos_reference' => 'TXN1']);
    }

    public function test_softpos_bypass_flags_the_payment_for_reconciliation(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 3000, 'status' => 'pending_reconciliation', 'softpos_reference' => 'NFC1'],
        ])])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $payment = Payment::firstWhere('order_id', $order->id);
        $this->assertSame('pending_reconciliation', $payment->status);
        $this->assertTrue((bool) $payment->pending_reconciliation);
    }

    public function test_a_broken_money_invariant_fails_only_that_event(): void
    {
        $this->device();

        $shift = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'sync.noop',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['opening_cash_baisas' => 50000],
        ];
        // 3000 − 0 + 0 ≠ 9999.
        $badCreate = $this->createEvent((string) Str::uuid(), ['grand_total_baisas' => 9999]);

        $res = $this->push('mdev_ord', [$shift, $badCreate])->assertOk();
        $results = $res->json('data.results');

        $this->assertSame('received', $results[0]['status']); // unhandled type, untouched
        $this->assertSame('failed', $results[1]['status']);
        $this->assertStringContainsString('invariant', $results[1]['result']['error']);

        $this->assertDatabaseCount('pos_orders', 0);
        // The failed event is still durably recorded in the ledger.
        $this->assertDatabaseHas('pos_sync_events', ['client_event_id' => $badCreate['client_event_id'], 'ack_status' => 'failed']);
    }

    public function test_paying_an_unknown_order_fails_the_event(): void
    {
        $this->device();

        $res = $this->push('mdev_ord', [$this->payEvent((string) Str::uuid())])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('order not found', $r['result']['error']);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_an_order_cannot_be_paid_from_another_branch(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10);
        $uuid = (string) Str::uuid();
        $this->push('mdev_a', [$this->createEvent($uuid)])->assertOk();

        // Same company, different branch — must not resolve the order.
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 11]);
        // Drop the cached pos_device guard user so the next request re-resolves
        // from the new bearer token (each real HTTP request is a fresh guard).
        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_b', [$this->payEvent($uuid)])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('order not found', $r['result']['error']);
        $this->assertSame('open', Order::firstWhere('uuid', $uuid)->status);
    }

    public function test_order_create_rejects_a_product_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device(); // company 100 / branch 10
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign Latte', 'base_price' => 9.999, 'status' => 'active'] + $t,
        ]);

        $event = $this->createEvent((string) Str::uuid(), [
            'subtotal_baisas' => 1000, 'grand_total_baisas' => 1000,
            'lines' => [['product_id' => 99, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]],
        ]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('outside the device tenant', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_order_create_rejects_an_addon_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_addons')->insert([
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'add_on_group_id' => 1, 'name' => 'Foreign shot', 'price_delta' => 0.500, 'status' => 'active'] + $t,
        ]);

        $event = $this->createEvent((string) Str::uuid(), [
            'lines' => [[
                'product_id' => 1, 'qty' => 2, 'unit_price_baisas' => 1500, 'line_total_baisas' => 3000,
                'addons' => [['add_on_id' => 99, 'price_delta_baisas' => 500]],
            ]],
        ]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('outside the device tenant', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_order_create_rejects_a_customer_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_customers')->insert([
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign Cust', 'phone' => '90000099'] + $t,
        ]);

        $event = $this->createEvent((string) Str::uuid(), ['customer_id' => 99]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('customer', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_order_create_rejects_a_table_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'floor_id' => 1, 'label' => 'T99'] + $t,
        ]);

        $event = $this->createEvent((string) Str::uuid(), ['table_id' => 99]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('table', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_order_create_accepts_a_customer_from_the_same_company(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_customers')->insert([
            ['id' => 5, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Own Cust', 'phone' => '90000005'] + $t,
        ]);

        $uuid = (string) Str::uuid();
        $r = $this->push('mdev_ord', [$this->createEvent($uuid, ['customer_id' => 5])])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame(5, (int) Order::firstWhere('uuid', $uuid)->customer_id);
    }

    public function test_idempotency_is_scoped_per_device_not_globally(): void
    {
        $this->device('mdev_a', 100, 10);
        Device::factory()->paired('mdev_b')->create(['company_id' => 200, 'branch_id' => 20]);
        $noop = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'sync.noop', 'client_timestamp' => now()->toIso8601String(), 'payload' => ['noop' => true]];

        // Device A is first to use this client_event_id.
        $a = $this->push('mdev_a', [$noop])->assertOk()->json('data.results.0');
        $this->assertFalse($a['duplicate']);

        // Device B (a DIFFERENT company) reusing the SAME id is NOT a duplicate —
        // it must not be suppressed or leak A's ACK.
        $this->app['auth']->forgetGuards();
        $b = $this->push('mdev_b', [$noop])->assertOk()->json('data.results.0');
        $this->assertFalse($b['duplicate']);
        $this->assertDatabaseCount('pos_sync_events', 2);

        // A replaying its OWN id still dedups (same device).
        $this->app['auth']->forgetGuards();
        $replay = $this->push('mdev_a', [$noop])->assertOk()->json('data.results.0');
        $this->assertTrue($replay['duplicate']);
        $this->assertDatabaseCount('pos_sync_events', 2);
    }
}
