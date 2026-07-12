<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the order.create staff_id guard needs cashier 7 in the tenant.
        $this->seedPosStaff([7]);
    }

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

    public function test_unit_products_freeze_no_recipe_snapshot(): void
    {
        $this->seedCatalogue();
        $this->device();

        // PD2 — a ready/bought-in product whose made-to-order past left
        // stale recipe rows: its cost is booked at RECEIVE (the
        // stock-purchase expense), so freezing the recipe here would
        // consume ingredients that were never used AND double-count the
        // goods' cost in net profit (recipe COGS + the purchase expense).
        DB::table('pos_products')->where('id', 1)->update(['stock_mode' => 'unit']);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $item = OrderItem::firstWhere('order_id', $order->id);
        $this->assertNull($item->recipe_snapshot_json);
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

    public function test_a_second_distinct_id_pay_does_not_double_deduct(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid), $this->payEvent($uuid)])->assertOk();

        $this->assertDatabaseCount('pos_stock_movements', 2);
        $this->assertEqualsWithDelta(4.5, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);

        // A SECOND order.pay for the same order with a DIFFERENT client_event_id
        // (not caught by the sync de-dup) must be refused by the in-transaction
        // status guard — inventory is never deducted twice.
        $res = $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();
        $r = $res->json('data.results.0');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('already paid', $r['result']['error']);

        $this->assertDatabaseCount('pos_stock_movements', 2); // still 2, no re-consume
        $this->assertEqualsWithDelta(4.5, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);
    }

    public function test_fractional_quantity_keeps_movements_and_balance_in_lockstep(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        // 0.25 qty x 0.25 L recipe = 0.0625 → rounds to 0.063. The movement row
        // and the branch_stock balance must use the SAME rounded value, so
        // Σ(movements) == balance exactly (no sub-0.001 drift).
        $create = $this->createEvent($uuid, [
            'subtotal_baisas' => 375,
            'grand_total_baisas' => 375,
            'lines' => [[
                'product_id' => 1,
                'qty' => 0.25,
                'unit_price_baisas' => 1500,
                'line_discount_baisas' => 0,
                'line_total_baisas' => 375,
                'addons' => [],
            ]],
        ]);
        $this->push('mdev_ord', [$create, $this->payEvent($uuid, [['method' => 'cash', 'amount_baisas' => 375, 'change_given_baisas' => 0]])])->assertOk();

        $balance = (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity');
        $movementSum = (float) \App\Models\StockMovement::where(['branch_id' => 10, 'ingredient_id' => 1])->sum('quantity');

        // The ledger invariant: start + Σ(movements) == balance.
        $this->assertEqualsWithDelta(5.0 + $movementSum, $balance, 1e-9);
        // Pinned: rounded once to 0.063 (a raw-float balance would store 4.938).
        $this->assertEqualsWithDelta(4.937, $balance, 1e-9);
    }

    public function test_a_failed_pay_event_is_retried_on_re_push(): void
    {
        $this->seedCatalogue();
        $device = $this->device();
        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk(); // order is OPEN

        // Simulate a prior TRANSIENT failure: a `failed` ledger row whose handler
        // txn rolled back (nothing settled). The device re-pushes the same id.
        $pay = $this->payEvent($uuid);
        \App\Models\SyncEvent::create([
            'client_event_id' => $pay['client_event_id'],
            'device_id' => $device->id,
            'event_type' => 'order.pay',
            'payload_json' => $pay['payload'],
            'client_timestamp' => now(),
            'server_received_at' => now(),
            'ack_status' => \App\Models\SyncEvent::STATUS_FAILED,
            'result_json' => ['error' => 'deadlock'],
        ]);

        $res = $this->push('mdev_ord', [$pay])->assertOk();
        $r = $res->json('data.results.0');

        // Re-dispatched (not swallowed): it now settles exactly once.
        $this->assertTrue($r['duplicate']);
        $this->assertSame('processed', $r['status']);
        $this->assertSame('paid', Order::firstWhere('uuid', $uuid)->status);
        $this->assertDatabaseCount('pos_stock_movements', 2);
        $this->assertEqualsWithDelta(4.5, (float) BranchStock::where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'), 1e-9);
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

    public function test_payment_snapshots_the_devices_acquirer_facts_and_bank_response(): void
    {
        $this->seedCatalogue();
        $device = Device::factory()->paired('mdev_ord')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'terminal_id' => 'TERM-9001',
            'bank_id' => 42,
        ]);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 3000, 'softpos_reference' => 'TXN9', 'softpos_auth_code' => 'AUTH9', 'bank_response' => ['rrn' => 'TXN9', 'authCode' => 'AUTH9', 'result' => 'SUCCESS']],
        ])])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $payment = Payment::firstWhere('order_id', $order->id);

        $this->assertSame($device->id, (int) $payment->device_id);
        $this->assertSame('TERM-9001', $payment->terminal_id);
        $this->assertSame(42, (int) $payment->bank_id);
        $this->assertSame('SUCCESS', $payment->bank_response['result']);
        $this->assertSame('TXN9', $payment->bank_response['rrn']);
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

    public function test_order_create_rejects_a_staff_member_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device(); // company 100 / branch 10 (cashier 7 is seeded in setUp)
        // A staff row that exists, but in a DIFFERENT company — a device must
        // not stamp its order (→ stock movements' recorded_by + the Z-report
        // drawer attribution) with a foreign cashier's id.
        $this->seedPosStaff([88], companyId: 200, branchId: 20);

        $event = $this->createEvent((string) Str::uuid(), ['staff_id' => 88]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('staff member outside the device tenant', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_order_create_accepts_a_soft_deleted_staff_member(): void
    {
        $this->seedCatalogue();
        $this->device();
        // An offline order queued by a since-terminated cashier must still
        // settle — the guard is withTrashed-tolerant.
        DB::table('pos_staff')->where('id', 7)->update(['deleted_at' => now()]);

        $uuid = (string) Str::uuid();
        $r = $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame(7, (int) Order::firstWhere('uuid', $uuid)->staff_id);
    }

    public function test_order_create_records_joined_tables_excluding_the_primary(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T1'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T2'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T3'] + $t,
        ]);

        $uuid = (string) Str::uuid();
        // The party's one order is billed on T1 (primary) and covers T2 + T3.
        // Send the primary in joined_table_ids too, to prove it is stripped.
        $r = $this->push('mdev_ord', [$this->createEvent($uuid, [
            'table_id' => 1,
            'joined_table_ids' => [1, 2, 3],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame(2, (int) $r['result']['joined_tables']);

        $order = Order::firstWhere('uuid', $uuid);
        $covered = DB::table('pos_order_tables')->where('order_id', $order->id)->pluck('table_id')->sort()->values()->all();
        $this->assertSame([2, 3], array_map('intval', $covered)); // primary T1 excluded
    }

    public function test_order_create_rejects_a_joined_table_from_another_company(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T1'] + $t,
            ['id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'floor_id' => 1, 'label' => 'T99'] + $t,
        ]);

        $event = $this->createEvent((string) Str::uuid(), [
            'table_id' => 1,
            'joined_table_ids' => [99],
        ]);
        $r = $this->push('mdev_ord', [$event])->assertOk()->json('data.results.0');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('joined table', $r['result']['error']);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_order_tables', 0);
    }

    public function test_re_pushing_an_order_replaces_its_joined_table_set(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T1'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T2'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T3'] + $t,
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid, [
            'table_id' => 1, 'joined_table_ids' => [2, 3],
        ])])->assertOk();
        $this->assertDatabaseCount('pos_order_tables', 2);

        // Re-push the same uuid (a re-hold / finalize) with a smaller joined
        // set — the stale T3 row must be purged, leaving only T2.
        $this->push('mdev_ord', [$this->createEvent($uuid, [
            'table_id' => 1, 'joined_table_ids' => [2],
        ])])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $covered = DB::table('pos_order_tables')->where('order_id', $order->id)->pluck('table_id')->all();
        $this->assertSame([2], array_map('intval', $covered));
        $this->assertDatabaseCount('pos_order_tables', 1);
    }

    public function test_joined_tables_are_ignored_on_a_non_dine_in_order(): void
    {
        $this->seedCatalogue();
        $this->device();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T2'] + $t,
        ]);

        // A to_go order has no primary table — joined tables are meaningless
        // and must NOT be recorded (no phantom sitting in the merchant report).
        $uuid = (string) Str::uuid();
        $r = $this->push('mdev_ord', [$this->createEvent($uuid, [
            'order_type' => 'to_go',
            'joined_table_ids' => [2],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame(0, (int) $r['result']['joined_tables']);
        $this->assertDatabaseCount('pos_order_tables', 0);
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

    public function test_paying_a_unit_tracked_product_decrements_its_branch_stock(): void
    {
        $this->seedCatalogue();
        $this->device(); // company 100 / branch 10
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => 20.000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk(); // product 1, qty 2

        // Not decremented at create.
        $this->assertEqualsWithDelta(20.0, (float) DB::table('pos_branch_product')->where(['branch_id' => 10, 'product_id' => 1])->value('stock_qty'), 1e-9);

        $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();

        // Decremented at pay: 20 - 2 = 18.
        $this->assertEqualsWithDelta(18.0, (float) DB::table('pos_branch_product')->where(['branch_id' => 10, 'product_id' => 1])->value('stock_qty'), 1e-9);

        // Phase D1 — the move also lands in the PRODUCT ledger so the
        // merchant Stock dialog's history shows the device sale. branch
        // side only: the central pool stays untouched.
        $this->assertDatabaseCount('pos_product_stock_movements', 1);
        $this->assertDatabaseHas('pos_product_stock_movements', [
            'company_id' => 100,
            'product_id' => 1,
            'branch_id' => 10,
            'movement_type' => 'sale_consumption',
            'reference_type' => 'pos_orders',
        ]);
        $this->assertEqualsWithDelta(-2.0, (float) DB::table('pos_product_stock_movements')->value('quantity'), 1e-9);
        $this->assertDatabaseCount('pos_product_stock', 0);
    }

    public function test_voiding_a_paid_order_restores_branch_product_stock(): void
    {
        $this->seedCatalogue();
        $this->device();
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => 20.000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();

        // Net zero: back to 20.
        $this->assertEqualsWithDelta(20.0, (float) DB::table('pos_branch_product')->where(['branch_id' => 10, 'product_id' => 1])->value('stock_qty'), 1e-9);

        // Phase D1 — pay + void = two signed sale_consumption ledger rows
        // summing to zero (the reversal convention the ingredient ledger uses).
        $this->assertDatabaseCount('pos_product_stock_movements', 2);
        $this->assertSame(
            2,
            DB::table('pos_product_stock_movements')->where('movement_type', 'sale_consumption')->count(),
        );
        $this->assertEqualsWithDelta(0.0, (float) DB::table('pos_product_stock_movements')->sum('quantity'), 1e-9);
    }

    public function test_an_untracked_product_is_unaffected_by_sales(): void
    {
        $this->seedCatalogue();
        $this->device();
        // Product 1 has a row but NULL stock_qty = not unit-tracked.
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk();

        // Still null — untracked products aren't decremented.
        $this->assertNull(DB::table('pos_branch_product')->where(['branch_id' => 10, 'product_id' => 1])->value('stock_qty'));

        // Phase D1 — and no product-ledger row either (untracked = no-op).
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    /** v2 #14 — seed a customer + a visit_based loyalty rule for company 100. */
    private function seedLoyaltyRule(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_customers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ali', 'phone' => '+96890000001'] + $t,
        ]);
        DB::table('pos_loyalty_rules')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Stamp card', 'type' => 'visit_based', 'config_json' => json_encode(['min_order_value' => '2.000', 'stamps_required' => 5]), 'status' => 'active'] + $t,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payWithLoyalty(string $orderUuid, int $ruleId): array
    {
        $event = $this->payEvent($orderUuid);
        $event['payload']['loyalty_rule_id'] = $ruleId;

        return $event;
    }

    /**
     * Phase D4 (blueprint §6.8): "Gift — Marks the order as gifted (zero
     * charged to customer). Inventory still deducts." A gift tender of the
     * full grand_total pays the order, deducts stock, records a 'gift'
     * payment row — and a fully gifted order earns NO loyalty (no spend ⇒
     * no points), even when the device names an earn rule.
     */
    public function test_a_gift_tender_pays_the_order_consumes_stock_and_earns_no_loyalty(): void
    {
        $this->seedCatalogue();
        $this->seedLoyaltyRule();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, ['customer_id' => 1])])->assertOk();

        $pay = $this->payEvent($uuid, [['method' => 'gift', 'amount_baisas' => 3000]]);
        $pay['payload']['loyalty_rule_id'] = 1;
        $res = $this->push('mdev_ord', [$pay])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertSame('paid', Order::firstWhere('uuid', $uuid)->status);
        $this->assertDatabaseHas('pos_payments', ['method' => 'gift', 'status' => 'success']);

        // Inventory still deducts: 2 Lattes × 0.25 L milk → 5.000 − 0.500.
        $this->assertEqualsWithDelta(
            4.5,
            (float) DB::table('pos_branch_stock')->where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'),
            1e-9,
        );

        // No loyalty earn on a fully gifted order.
        $this->assertSame([], $res->json('data.results.0.result.loyalty_transaction_ids'));
        $this->assertNull(LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first());
    }

    public function test_voiding_a_paid_order_reverses_the_loyalty_earn(): void
    {
        $this->seedCatalogue();
        $this->seedLoyaltyRule();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, ['customer_id' => 1])])->assertOk();
        $this->push('mdev_ord', [$this->payWithLoyalty($uuid, 1)])->assertOk();

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->firstOrFail();
        $this->assertSame(1, $account->stamp_count); // 3.000 ≥ 2.000 min → 1 stamp

        $res = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        $this->assertSame('voided', $res->json('data.results.0.result.status'));
        $this->assertSame(1, $res->json('data.results.0.result.loyalty_reversed'));

        // The stamp is clawed back to zero via an inverse `adjust` ledger row.
        $this->assertSame(0, $account->fresh()->stamp_count);
        $order = Order::firstWhere('uuid', $uuid);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'adjust',
            'stamps_delta' => -1,
            'balance_after_stamps' => 0,
            'order_id' => $order->id,
            'reason' => 'reversed from void',
        ]);
    }

    public function test_voiding_clamps_a_loyalty_clawback_to_the_available_balance(): void
    {
        $this->seedCatalogue();
        $this->seedLoyaltyRule();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, ['customer_id' => 1])])->assertOk();
        $this->push('mdev_ord', [$this->payWithLoyalty($uuid, 1)])->assertOk();

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->firstOrFail();
        // The customer already spent that stamp on a later visit — balance is 0.
        $account->update(['stamp_count' => 0]);

        $res = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        // Nothing left to claw back: no reversal row, balance never goes negative.
        $this->assertSame(0, $res->json('data.results.0.result.loyalty_reversed'));
        $this->assertSame(0, $account->fresh()->stamp_count);
        $this->assertDatabaseMissing('pos_loyalty_transactions', ['type' => 'adjust']);
    }

    public function test_voiding_voids_the_roundup_and_removes_the_commission(): void
    {
        config(['services.charity.url' => null]); // no charity forward in this unit test
        Http::fake();

        $this->seedCatalogue();
        Device::factory()->paired('mdev_ord')->create([
            'company_id' => 100, 'branch_id' => 10, 'bank_id' => 5, 'terminal_id' => 'TID-9', 'commission_profile_id' => 7,
        ]);

        $t = ['created_at' => now(), 'updated_at' => now()];
        $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'is_active' => true, 'merchant_percent' => 95,
        ] + $t);
        DB::table('pos_commission_shares')->insert([
            ['commission_profile_id' => $profileId, 'party_type' => 'platform', 'label' => 'Platform', 'percent' => 2, 'sort_order' => 0] + $t,
            ['commission_profile_id' => $profileId, 'party_type' => 'bank', 'label' => 'Bank', 'percent' => 3, 'sort_order' => 1] + $t,
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 3000, 'softpos_reference' => 'TXN1', 'softpos_auth_code' => 'A1'],
        ])])->assertOk();
        $this->push('mdev_ord', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'donation.record',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $uuid, 'amount_baisas' => 200, 'receipt' => ['status' => 'success']],
        ]])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame('success', RoundupDonation::where('order_id', $order->id)->firstOrFail()->status);
        $this->assertSame(3, DB::table('pos_sale_commissions')->where('order_id', $order->id)->count());
        $payment = Payment::where(['order_id' => $order->id, 'method' => 'card'])->firstOrFail();
        $this->assertSame('0.200', $payment->roundup_amount);

        $res = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        $this->assertSame(1, $res->json('data.results.0.result.roundup_voided'));
        $this->assertSame(3, $res->json('data.results.0.result.commission_removed'));

        // Round-up donation voided + the card payment's breadcrumbs cleared.
        $this->assertSame('void', RoundupDonation::where('order_id', $order->id)->firstOrFail()->status);
        $this->assertNull($payment->fresh()->roundup_amount);
        $this->assertNull($payment->fresh()->charity_transaction_id);
        // Commission breakdown gone — the voided sale leaves no payout trace.
        $this->assertSame(0, DB::table('pos_sale_commissions')->where('order_id', $order->id)->count());
    }

    public function test_voiding_keeps_commission_rows_already_claimed_by_a_payout(): void
    {
        $this->seedCatalogue();
        Device::factory()->paired('mdev_ord')->create([
            'company_id' => 100, 'branch_id' => 10, 'bank_id' => 5, 'terminal_id' => 'TID-9', 'commission_profile_id' => 7,
        ]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'is_active' => true, 'merchant_percent' => 95,
        ] + $t);
        DB::table('pos_commission_shares')->insert([
            ['commission_profile_id' => $profileId, 'party_type' => 'platform', 'label' => 'Platform', 'percent' => 2, 'sort_order' => 0] + $t,
            ['commission_profile_id' => $profileId, 'party_type' => 'bank', 'label' => 'Bank', 'percent' => 3, 'sort_order' => 1] + $t,
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_ord', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 3000, 'softpos_reference' => 'TXN1', 'softpos_auth_code' => 'A1'],
        ])])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        // A payout has CLAIMED the merchant row (the admin settled it).
        DB::table('pos_sale_commissions')->where(['order_id' => $order->id, 'party_type' => 'merchant'])->update(['payout_id' => 1]);

        $res = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();

        // Only the UNCLAIMED platform + bank rows are reversed; the claimed
        // merchant row survives so the payout snapshot stays backed.
        $this->assertSame(2, $res->json('data.results.0.result.commission_removed'));
        $this->assertSame(1, DB::table('pos_sale_commissions')->where('order_id', $order->id)->whereNotNull('payout_id')->count());
        $this->assertSame(0, DB::table('pos_sale_commissions')->where('order_id', $order->id)->whereNull('payout_id')->count());
    }
}
