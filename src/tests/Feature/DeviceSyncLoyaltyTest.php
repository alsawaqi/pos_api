<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.4 — server-authoritative loyalty earn at sale.
 *
 * When a paid order has a customer and the order.pay event names a
 * loyalty_rule_id, the server runs the shared EvaluateLoyalty on the
 * post-discount subtotal and writes an `earn` row into the ledger, bumping
 * the account balance. Seeded for company 100 / branch 10: a 3.000 OMR
 * product, customer 1, a visit_based rule (id 1) and a spend_based rule
 * (id 2), plus a rule belonging to another company (id 9).
 */
class DeviceSyncLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_ord', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedLoyalty(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 3.000, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_customers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ali', 'phone' => '+96890000000', 'wallet_balance' => 0] + $t,
        ]);
        DB::table('pos_loyalty_rules')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Stamp card', 'type' => 'visit_based', 'config_json' => json_encode(['min_order_value' => '2.000', 'stamps_required' => 5]), 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Points', 'type' => 'spend_based', 'config_json' => json_encode(['points_per_omr' => 10, 'redemption_points' => 100, 'min_redemption_points' => 100, 'redemption_value' => '5.000']), 'status' => 'active'] + $t,
            ['id' => 9, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'OtherCo', 'type' => 'visit_based', 'config_json' => json_encode(['min_order_value' => '0.000', 'stamps_required' => 1]), 'status' => 'active'] + $t,
        ]);
    }

    private function createEvent(string $orderUuid, ?int $customerId = 1): array
    {
        $order = [
            'uuid' => $orderUuid,
            'order_type' => 'quick',
            'source' => 'main_pos',
            'staff_id' => 7,
            'opened_at' => now()->toIso8601String(),
            'subtotal_baisas' => 3000,
            'discount_total_baisas' => 0,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 3000,
            'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
        ];
        if ($customerId !== null) {
            $order['customer_id'] = $customerId;
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => $order],
        ];
    }

    private function payEvent(string $orderUuid, ?int $loyaltyRuleId = null, ?array $redeem = null): array
    {
        $payload = [
            'order_uuid' => $orderUuid,
            'paid_at' => now()->toIso8601String(),
            'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
        ];
        if ($loyaltyRuleId !== null) {
            $payload['loyalty_rule_id'] = $loyaltyRuleId;
        }
        if ($redeem !== null) {
            $payload['loyalty_redeem'] = $redeem;
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_visit_based_rule_earns_a_stamp_on_pay(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, 1)])->assertOk();

        $this->assertNotNull($res->json('data.results.0.result.loyalty_transaction_id'));

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first();
        $this->assertNotNull($account);
        $this->assertSame(1, $account->stamp_count);
        $this->assertSame(0, $account->point_balance);
        $this->assertNotNull($account->last_activity_at);

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'earn',
            'stamps_delta' => 1,
            'balance_after_stamps' => 1,
            'order_id' => $order->id,
        ]);
    }

    public function test_spend_based_rule_earns_points_on_pay(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $this->push('mdev_ord', [$this->payEvent($uuid, 2)])->assertOk();

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->first();
        $this->assertSame(30, $account->point_balance); // 3.000 OMR × 10 points/OMR
        $this->assertSame(0, $account->stamp_count);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'earn',
            'points_delta' => 30,
            'balance_after_points' => 30,
        ]);
    }

    public function test_no_loyalty_is_written_without_a_rule_id(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid)])->assertOk(); // no loyalty_rule_id

        $this->assertNull($res->json('data.results.0.result.loyalty_transaction_id'));
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
        $this->assertDatabaseCount('pos_loyalty_accounts', 0);
    }

    public function test_no_loyalty_is_written_without_a_customer(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, null)])->assertOk(); // walk-in, no customer
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, 1)])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertNull($res->json('data.results.0.result.loyalty_transaction_id'));
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_stamps_accrue_across_orders_with_running_balances(): void
    {
        $this->seedLoyalty();
        $this->device();

        foreach ([1, 2] as $n) {
            $uuid = (string) Str::uuid();
            $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
            $this->push('mdev_ord', [$this->payEvent($uuid, 1)])->assertOk();
        }

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first();
        $this->assertSame(2, $account->stamp_count);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
        $this->assertSame(2, (int) LoyaltyTransaction::max('balance_after_stamps'));
    }

    public function test_a_cross_tenant_rule_is_ignored(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        // Rule 9 belongs to company 200; the order is company 100.
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, 9)])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertNull($res->json('data.results.0.result.loyalty_transaction_id'));
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_replaying_pay_does_not_earn_twice(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();
        $create = $this->createEvent($uuid, 1);
        $pay = $this->payEvent($uuid, 1);

        $this->push('mdev_ord', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_loyalty_transactions', 1);

        $res = $this->push('mdev_ord', [$create, $pay])->assertOk();
        $res->assertJsonPath('data.summary.duplicates', 2);

        $this->assertDatabaseCount('pos_loyalty_transactions', 1);
        $this->assertSame(1, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first()->stamp_count);
    }

    private function seedAccount(int $ruleId, int $points = 0, int $stamps = 0): void
    {
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => 1,
            'loyalty_rule_id' => $ruleId,
            'point_balance' => $points,
            'stamp_count' => $stamps,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_redemption_decrements_the_balance(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: 100); // spend-based account with 100 pts
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, null, ['rule_id' => 2, 'points' => 40])])->assertOk();

        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->first();
        $this->assertSame(60, $account->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'points_delta' => -40,
            'balance_after_points' => 60,
        ]);
    }

    public function test_earn_and_redeem_can_both_happen_in_one_pay(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: 100);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        // Earn on the visit rule (1); redeem points on the spend rule (2).
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, 1, ['rule_id' => 2, 'points' => 40])])->assertOk();

        $this->assertNotNull($res->json('data.results.0.result.loyalty_transaction_id'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $this->assertSame(1, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first()->stamp_count);
        $this->assertSame(60, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->first()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
    }

    public function test_redeeming_more_than_the_balance_fails_the_pay(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: 30);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, null, ['rule_id' => 2, 'points' => 50])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('negative', $res->json('data.results.0.result.error'));
        // The whole pay rolled back: order still open, balance intact, no ledger row.
        $this->assertSame('open', Order::firstWhere('uuid', $uuid)->status);
        $this->assertSame(30, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->first()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_redeeming_without_an_account_fails(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, null, ['rule_id' => 2, 'points' => 10])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('no loyalty account', $res->json('data.results.0.result.error'));
        $this->assertSame('open', Order::firstWhere('uuid', $uuid)->status);
    }
}
