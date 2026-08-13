<?php

declare(strict_types=1);

namespace Tests\Feature\Phase0Exit;

use App\Models\Customer;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 0 exit — W-A2 / EXIT-05.
 *
 * "Customer deleted before queued replay: never discarded / falsely
 * processed; rejection parks and is visible for manual action."
 *
 * The pos_api test schema (sqlite) carries no foreign keys, so the two
 * customer-hard-delete effects the PRODUCTION schema guarantees are mirrored
 * explicitly wherever a test hard-deletes the customer (both verified
 * read-only in pos_admin's migrations, which own the shared schema):
 *
 *   - pos_orders.customer_id      → constrained(pos_customers)->nullOnDelete()
 *                                   (2026_06_04_010000_create_pos_orders_table)
 *   - pos_loyalty_accounts.customer_id → constrained(pos_customers)->cascadeOnDelete()
 *                                   (2026_06_08_010100_create_pos_loyalty_accounts_table)
 *
 * A SOFT delete fires neither FK: the row survives with deleted_at set, so
 * the order's attribution and the loyalty account both remain intact.
 */
class DeletedCustomerReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);

        config([
            'sync.stranded_sweep_after_id' => 0,
            'sync.stranded_sweep_enabled' => false,
            'services.charity.url' => null,
        ]);
    }

    private function device(): Device
    {
        $device = Device::factory()->paired('mdev_p0_cust')->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
        $device->forceFill(['assigned_at' => now()->subHour()])->save();

        return $device;
    }

    /** A 3.000 OMR product, customer 1, spend rule 2 and a 100-point account. */
    private function seedLoyaltyCustomer(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 3.000, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_customers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ali', 'phone' => '+96890000000', 'wallet_balance' => 0] + $t,
        ]);
        DB::table('pos_loyalty_rules')->insert([
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Points', 'type' => 'spend_based', 'config_json' => json_encode(['points_per_omr' => 10, 'redemption_points' => 100, 'min_redemption_points' => 100, 'redemption_value' => '5.000']), 'status' => 'active'] + $t,
        ]);
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => 1,
            'loyalty_rule_id' => 2,
            'point_balance' => 100,
            'stamp_count' => 0,
        ] + $t);
    }

    /**
     * Hard-delete customer 1 the way PRODUCTION does it: the row goes away
     * and the schema's FK actions fire (mirrored manually — see class doc).
     */
    private function hardDeleteCustomerMirroringProductionFks(): void
    {
        Customer::withTrashed()->findOrFail(1)->forceDelete();
        DB::table('pos_orders')->where('customer_id', 1)->update(['customer_id' => null]);
        DB::table('pos_loyalty_accounts')->where('customer_id', 1)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => 7,
                'customer_id' => 1,
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, array $extra = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge([
                'order_uuid' => $orderUuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
            ], $extra),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events): TestResponse
    {
        return $this->withToken('mdev_p0_cust')->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_pay_redeeming_for_a_hard_deleted_customer_fails_visibly_and_parks(): void
    {
        $this->seedLoyaltyCustomer();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push([$this->createEvent($uuid)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // The customer vanishes while the pay sits in the device outbox.
        $this->hardDeleteCustomerMirroringProductionFks();

        $pay = $this->payEvent($uuid, ['loyalty_redeem' => ['rule_id' => 2, 'points' => 40]]);
        $res = $this->push([$pay])->assertOk();

        // FAILS VISIBLY with the deterministic, parkable error the device can
        // surface for manual action — never a silent settle, never a discard.
        $this->assertFalse($res->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_FAILED, $res->json('data.results.0.status'));
        $this->assertSame(
            'cannot redeem loyalty without a customer on the order',
            $res->json('data.results.0.result.error'),
        );

        // The order/payment state stayed consistent: nothing half-settled.
        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame(Order::STATUS_OPEN, $order->status);
        $this->assertNull($order->customer_id); // production FK effect
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);

        // Durably parked in the ledger — visible, not discarded.
        $this->assertDatabaseHas('pos_sync_events', [
            'client_event_id' => $pay['client_event_id'],
            'ack_status' => SyncEvent::STATUS_FAILED,
        ]);

        // A retry of the parked event is re-dispatched (failed is retryable by
        // contract) and fails the SAME deterministic way — stable, no effects.
        $retry = $this->push([$pay])->assertOk();
        $this->assertTrue($retry->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_FAILED, $retry->json('data.results.0.status'));
        $this->assertSame(
            'cannot redeem loyalty without a customer on the order',
            $retry->json('data.results.0.result.error'),
        );
        $this->assertDatabaseCount('pos_sync_events', 2); // create + pay, no extra rows
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertSame(Order::STATUS_OPEN, $order->fresh()->status);
    }

    public function test_pay_redeeming_for_a_soft_deleted_customer_settles_once_with_attribution_intact(): void
    {
        $this->seedLoyaltyCustomer();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push([$this->createEvent($uuid)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // SOFT delete: the customer row survives (deleted_at set), no FK
        // fires, the loyalty account remains. This is the deletion the
        // portals actually perform. The repo's settlement design is
        // withTrashed-tolerant throughout (order.create accepts soft-deleted
        // staff / products / add-ons; branch reports label soft-deleted
        // customers), so the queued sale of a since-archived customer must
        // SETTLE — exactly once, with its attribution and exact debit —
        // rather than be rejected and stranded. That satisfies EXIT-05:
        // processed truly, never falsely, never discarded.
        Customer::query()->findOrFail(1)->delete();
        $this->assertNotNull(DB::table('pos_customers')->where('id', 1)->value('deleted_at'));

        $pay = $this->payEvent($uuid, ['loyalty_redeem' => ['rule_id' => 2, 'points' => 40]]);
        $res = $this->push([$pay])->assertOk();

        $this->assertSame(SyncEvent::STATUS_PROCESSED, $res->json('data.results.0.status'));
        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $this->assertNull($res->json('data.results.0.result.loyalty_redeem_adjustment_id')); // 40 ≤ 100: no shortfall

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(1, (int) $order->customer_id); // attribution intact
        $this->assertDatabaseCount('pos_payments', 1);

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail();
        $this->assertSame(60, $account->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'points_delta' => -40,
            'balance_after_points' => 60,
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseCount('pos_loyalty_transactions', 1);

        // Replay settles nothing twice.
        $replay = $this->push([$pay])->assertOk();
        $this->assertTrue($replay->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $replay->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_loyalty_transactions', 1);
        $this->assertSame(60, $account->fresh()->point_balance);
    }

    public function test_pay_with_earn_context_never_settles_anonymously_after_a_hard_delete(): void
    {
        $this->seedLoyaltyCustomer();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push([$this->createEvent($uuid)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $this->hardDeleteCustomerMirroringProductionFks();

        // The pay names an EARN rule — loyalty context the device only sends
        // because the order carried this customer. EXIT-05 allows exactly two
        // outcomes: fail visibly (parkable) OR process with the promised
        // attribution intact. What it forbids is the silent middle: settling
        // as an anonymous NULL-customer walk-in with the earn dropped.
        $pay = $this->payEvent($uuid, ['loyalty_rule_id' => 2]);
        $res = $this->push([$pay])->assertOk();
        $status = $res->json('data.results.0.status');

        if ($status === SyncEvent::STATUS_PROCESSED) {
            $order = Order::firstWhere('uuid', $uuid);
            $this->assertNotNull(
                $order->customer_id,
                'EXIT-05 W-A2: order.pay carrying loyalty earn context settled as an anonymous walk-in sale after the '
                .'customer hard-delete (order.customer_id was NULLed by the production FK) — the sale was silently '
                .'processed with a NULL customer instead of failing visibly for operator action.',
            );
            $this->assertNotEmpty(
                $res->json('data.results.0.result.loyalty_transaction_ids'),
                'EXIT-05 W-A2: the pay settled but the earn the device named was silently dropped.',
            );
        } else {
            // The alternative sanctioned outcome: a visible, parkable failure.
            $this->assertSame(SyncEvent::STATUS_FAILED, $status);
            $error = $res->json('data.results.0.result.error');
            $this->assertIsString($error);
            $this->assertNotSame('', $error);
            $this->assertSame(Order::STATUS_OPEN, Order::firstWhere('uuid', $uuid)->status);
            $this->assertDatabaseCount('pos_payments', 0);
        }
    }

    public function test_a_stranded_pay_whose_customer_vanished_lands_failed_with_the_error_visible(): void
    {
        $this->seedLoyaltyCustomer();
        $device = $this->device();
        $uuid = (string) Str::uuid();

        $this->push([$this->createEvent($uuid)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // The pay reached the durable ledger, then its worker died. While it
        // sat stranded, the customer was hard-deleted.
        $stranded = SyncEvent::create([
            'client_event_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'order.pay',
            'payload_json' => [
                'order_uuid' => $uuid,
                'paid_at' => now()->subMinutes(12)->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
                'loyalty_redeem' => ['rule_id' => 2, 'points' => 40],
            ],
            'client_timestamp' => now()->subMinutes(12),
            'server_received_at' => now()->subMinutes(11),
            'ack_status' => SyncEvent::STATUS_RECEIVED,
        ]);
        $this->hardDeleteCustomerMirroringProductionFks();

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=0 failed=1 skipped=0')
            ->assertSuccessful();

        // FAILED with the error durably visible in result_json — never
        // falsely processed, never left received forever.
        $stranded->refresh();
        $this->assertSame(SyncEvent::STATUS_FAILED, $stranded->ack_status);
        $this->assertNotNull($stranded->processed_at);
        $this->assertSame(
            'cannot redeem loyalty without a customer on the order',
            (string) $stranded->result_json['error'],
        );

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame(Order::STATUS_OPEN, $order->status);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);

        // The failure is TERMINAL for the sweep: later runs neither skip it
        // forever as received nor resurrect it into a false settlement.
        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=0 failed=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_FAILED, $stranded->fresh()->ack_status);
        $this->assertSame(Order::STATUS_OPEN, $order->fresh()->status);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_donation_record_for_an_order_whose_customer_vanished_records_the_supported_outcome(): void
    {
        Http::fake();
        $this->seedLoyaltyCustomer();
        $device = $this->device();

        DB::table('pos_branches')->insert([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main',
            'latitude' => 23.5880000, 'longitude' => 58.4060000,
            'country_id' => 1, 'region_id' => 2, 'district_id' => 3, 'city_id' => 4,
            'geofence_radius_m' => 500, 'default_order_type' => 'dine_in', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A settled card sale rung FOR customer 1 (paid before the deletion).
        $orderId = DB::table('pos_orders')->insertGetId([
            'uuid' => 'p0-donation-order', 'company_id' => 100, 'branch_id' => 10,
            'customer_id' => 1, 'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
            'subtotal' => '4.800', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '4.800',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = DB::table('pos_payments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
            'amount' => '5.000', 'status' => 'success', 'pending_reconciliation' => false,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The queued donation.record replays only after the customer vanished.
        $this->hardDeleteCustomerMirroringProductionFks();
        $this->assertNull(DB::table('pos_orders')->where('id', $orderId)->value('customer_id'));

        $event = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'donation.record',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => 'p0-donation-order',
                'amount_baisas' => 200,
                'receipt' => ['status' => 'success', 'approvalCode' => 'XYZ'],
            ],
        ];
        $res = $this->push([$event])->assertOk();

        // PINNED supported outcome: the round-up is card money, independent of
        // the customer identity — it records and settles normally. It is never
        // discarded and never mis-attributed (all snapshots are order/payment/
        // branch facts).
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $res->json('data.results.0.status'));
        $this->assertSame('success', $res->json('data.results.0.result.status'));
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        $this->assertDatabaseHas('pos_roundup_donations', [
            'company_id' => 100,
            'branch_id' => 10,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'device_id' => $device->id,
            'status' => 'success',
            'source' => 'pos_roundup',
        ]);
        $donation = RoundupDonation::firstOrFail();
        $this->assertSame('0.200', $donation->amount);
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('0.200', $payment->roundup_amount);
        $this->assertSame((int) $donation->id, (int) $payment->charity_transaction_id);
        Http::assertNothingSent(); // charity url unset in this suite

        // Replay never duplicates the charity money.
        $replay = $this->push([$event])->assertOk();
        $this->assertTrue($replay->json('data.results.0.duplicate'));
        $this->assertDatabaseCount('pos_roundup_donations', 1);
    }
}
