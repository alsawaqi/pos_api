<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\SyncEvent;
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

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the loyalty order's cashier (staff 7) is in the tenant.
        $this->seedPosStaff([7]);
    }

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

    private function seedSettlementEffects(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->where('id', 1)->update(['base_price' => 8.000]);
        DB::table('pos_ingredients')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Milk',
            'unit' => 'l',
            'default_unit_cost' => 0.400,
            'status' => 'active',
        ] + $t);
        DB::table('pos_product_recipes')->insert([
            'product_id' => 1,
            'ingredient_id' => 1,
            'quantity' => 0.250,
            'unit_at_set' => 'l',
            'sort_order' => 1,
        ] + $t);
        DB::table('pos_branch_stock')->insert([
            'branch_id' => 10,
            'ingredient_id' => 1,
            'quantity' => 5.000,
        ] + $t);

        $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'is_active' => true,
            'merchant_percent' => 98,
        ] + $t);
        DB::table('pos_commission_shares')->insert([
            'commission_profile_id' => $profileId,
            'party_type' => 'platform',
            'label' => 'Platform',
            'percent' => 2,
            'applies_to' => 'all',
            'sort_order' => 0,
        ] + $t);
    }

    private function createEvent(string $orderUuid, ?int $customerId = 1, int $subtotalBaisas = 3000, int $discountBaisas = 0): array
    {
        $order = [
            'uuid' => $orderUuid,
            'order_type' => 'quick',
            'source' => 'main_pos',
            'staff_id' => 7,
            'opened_at' => now()->toIso8601String(),
            'subtotal_baisas' => $subtotalBaisas,
            'discount_total_baisas' => $discountBaisas,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => $subtotalBaisas - $discountBaisas,
            'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => $subtotalBaisas, 'line_discount_baisas' => 0, 'line_total_baisas' => $subtotalBaisas]],
        ];
        if ($customerId !== null) {
            $order['customer_id'] = $customerId;
        }
        if ($discountBaisas > 0) {
            $order['discounts'] = [['name' => 'Loyalty redemption', 'amount_baisas' => $discountBaisas]];
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => $order],
        ];
    }

    private function payEvent(string $orderUuid, ?int $loyaltyRuleId = null, ?array $redeem = null, int $amountBaisas = 3000): array
    {
        $payload = [
            'order_uuid' => $orderUuid,
            'paid_at' => now()->toIso8601String(),
            'payments' => [['method' => 'cash', 'amount_baisas' => $amountBaisas]],
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

    private function voidEvent(string $orderUuid): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'customer left',
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

    public function test_over_balance_redemption_settles_every_sale_effect_and_flags_the_shortfall(): void
    {
        $this->seedLoyalty();
        $this->seedSettlementEffects();
        $this->device();
        $this->seedAccount(2, points: 30);
        $uuid = (string) Str::uuid();
        $create = $this->createEvent($uuid, 1, subtotalBaisas: 8000, discountBaisas: 5000);
        $pay = $this->payEvent($uuid, 1, ['rule_id' => 2, 'points' => 100], amountBaisas: 3000);

        $this->push('mdev_ord', [$create])->assertOk();
        $res = $this->push('mdev_ord', [$pay])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertCount(1, $res->json('data.results.0.result.payment_ids'));
        $this->assertSame(1, $res->json('data.results.0.result.movements'));
        $this->assertCount(2, $res->json('data.results.0.result.sale_commission_ids'));
        $this->assertCount(1, $res->json('data.results.0.result.loyalty_transaction_ids'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_adjustment_id'));
        $this->assertStringContainsString(
            '[LOYALTY_REDEMPTION_SHORTFALL][REVIEW_REQUIRED]',
            $res->json('data.results.0.result.loyalty_redeem_warning'),
        );

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertDatabaseHas('pos_payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'status' => 'success',
        ]);
        $this->assertEqualsWithDelta(3.000, (float) DB::table('pos_payments')->value('amount'), 1e-9);

        $this->assertEqualsWithDelta(
            4.750,
            (float) DB::table('pos_branch_stock')->where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'),
            1e-9,
        );
        $this->assertDatabaseHas('pos_stock_movements', [
            'branch_id' => 10,
            'ingredient_id' => 1,
            'movement_type' => 'sale_consumption',
            'reference_type' => 'pos_orders',
            'reference_id' => $order->id,
        ]);
        $this->assertEqualsWithDelta(-0.250, (float) DB::table('pos_stock_movements')->value('quantity'), 1e-9);

        $commissions = DB::table('pos_sale_commissions')->where('order_id', $order->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $commissions);
        $this->assertSame('platform', $commissions[0]->party_type);
        $this->assertEqualsWithDelta(0.060, (float) $commissions[0]->commission_amount, 1e-9);
        $this->assertSame('merchant', $commissions[1]->party_type);
        $this->assertEqualsWithDelta(2.940, (float) $commissions[1]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(3.000, $commissions->sum(fn ($row): float => (float) $row->commission_amount), 1e-9);

        $earnAccount = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->firstOrFail();
        $redeemAccount = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail();
        $this->assertSame(1, $earnAccount->stamp_count);
        $this->assertSame(0, $redeemAccount->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $earnAccount->id,
            'type' => 'earn',
            'stamps_delta' => 1,
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $redeemAccount->id,
            'type' => 'redeem',
            'points_delta' => -30,
            'balance_after_points' => 0,
            'order_id' => $order->id,
        ]);
        $marker = LoyaltyTransaction::query()
            ->where('loyalty_account_id', $redeemAccount->id)
            ->where('type', LoyaltyTransaction::TYPE_ADJUST)
            ->firstOrFail();
        $this->assertSame(0, $marker->points_delta);
        $this->assertSame(0, $marker->stamps_delta);
        $this->assertSame(0, $marker->balance_after_points);
        $this->assertSame(0, $marker->balance_after_stamps);
        $this->assertSame((int) $order->id, (int) $marker->order_id);
        $this->assertNull($marker->recorded_by_user_id);
        $this->assertStringContainsString('requested points=100 stamps=0', (string) $marker->reason);
        $this->assertStringContainsString('applied points=30 stamps=0', (string) $marker->reason);
        $this->assertStringContainsString('shortfall points=70 stamps=0', (string) $marker->reason);
        $this->assertDatabaseCount('pos_loyalty_transactions', 3);

        $replay = $this->push('mdev_ord', [$pay])->assertOk();
        $this->assertTrue($replay->json('data.results.0.duplicate'));
        $replay->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_stock_movements', 1);
        $this->assertDatabaseCount('pos_sale_commissions', 2);
        $this->assertDatabaseCount('pos_loyalty_transactions', 3);

        $void = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        $this->assertSame('voided', $void->json('data.results.0.result.status'));
        $this->assertSame(2, $void->json('data.results.0.result.loyalty_reversed'));
        $this->assertSame(30, $redeemAccount->fresh()->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $redeemAccount->id,
            'type' => 'adjust',
            'points_delta' => 30,
            'reason' => 'reversed from void',
            'order_id' => $order->id,
        ]);
        $this->assertSame(
            1,
            LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->where('reason', 'like', '[LOYALTY_REDEMPTION_SHORTFALL]%')
                ->count(),
        );
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

    public function test_negative_loyalty_redemption_counts_are_rejected(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: 100, stamps: 100);

        $cases = [
            'mixed negative points' => ['rule_id' => 2, 'points' => -1, 'stamps' => 10],
            'mixed negative stamps' => ['rule_id' => 2, 'points' => 10, 'stamps' => -1],
            'negative points only' => ['rule_id' => 2, 'points' => -1, 'stamps' => 0],
            'negative stamps only' => ['rule_id' => 2, 'points' => 0, 'stamps' => -1],
            'both negative' => ['rule_id' => 2, 'points' => -1, 'stamps' => -1],
            'negative with unaffordable positive leg' => ['rule_id' => 2, 'points' => -1, 'stamps' => 101],
        ];

        foreach ($cases as $label => $redeem) {
            $uuid = (string) Str::uuid();
            $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
            $res = $this->push('mdev_ord', [$this->payEvent($uuid, null, $redeem)])->assertOk();

            $this->assertSame('failed', $res->json('data.results.0.status'), $label);
            $this->assertStringContainsString(
                'points and stamps must be non-negative',
                $res->json('data.results.0.result.error'),
                $label,
            );
            $this->assertSame(Order::STATUS_OPEN, Order::firstWhere('uuid', $uuid)->status, $label);

            $account = LoyaltyAccount::where([
                'customer_id' => 1,
                'loyalty_rule_id' => 2,
            ])->firstOrFail();
            $this->assertSame(100, $account->point_balance, $label);
            $this->assertSame(100, $account->stamp_count, $label);
        }

        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_zero_available_stamp_balance_settles_with_a_marker_only(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(1);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [
            $this->payEvent($uuid, null, ['rule_id' => 1, 'stamps' => 1]),
        ])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_adjustment_id'));

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->firstOrFail();
        $marker = LoyaltyTransaction::firstOrFail();
        $this->assertSame(0, $account->stamp_count);
        $this->assertSame(LoyaltyTransaction::TYPE_ADJUST, $marker->type);
        $this->assertSame(0, $marker->points_delta);
        $this->assertSame(0, $marker->stamps_delta);
        $this->assertSame(0, $marker->balance_after_stamps);
        $this->assertStringContainsString('requested points=0 stamps=1', (string) $marker->reason);
        $this->assertStringContainsString('applied points=0 stamps=0', (string) $marker->reason);
        $this->assertStringContainsString('shortfall points=0 stamps=1', (string) $marker->reason);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_loyalty_transactions', 1);
    }

    public function test_points_and_stamps_clamp_independently_against_the_post_earn_balance(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: 20, stamps: 1);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [
            $this->payEvent($uuid, 2, ['rule_id' => 2, 'points' => 50, 'stamps' => 3]),
        ])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_transaction_id'));
        $this->assertNotNull($res->json('data.results.0.result.loyalty_redeem_adjustment_id'));

        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail();
        $this->assertSame(0, $account->point_balance);
        $this->assertSame(0, $account->stamp_count);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'earn',
            'points_delta' => 30,
            'balance_after_points' => 50,
        ]);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'points_delta' => -50,
            'stamps_delta' => -1,
            'balance_after_points' => 0,
            'balance_after_stamps' => 0,
        ]);
        $marker = LoyaltyTransaction::where('type', LoyaltyTransaction::TYPE_ADJUST)->firstOrFail();
        $this->assertStringContainsString('requested points=50 stamps=3', (string) $marker->reason);
        $this->assertStringContainsString('applied points=50 stamps=1', (string) $marker->reason);
        $this->assertStringContainsString('shortfall points=0 stamps=2', (string) $marker->reason);
        $this->assertDatabaseCount('pos_loyalty_transactions', 3);

        $order = Order::firstWhere('uuid', $uuid);
        $void = $this->push('mdev_ord', [$this->voidEvent($uuid)])->assertOk();
        $this->assertSame('voided', $void->json('data.results.0.result.status'));
        $this->assertSame(2, $void->json('data.results.0.result.loyalty_reversed'));
        $this->assertSame(20, $account->fresh()->point_balance);
        $this->assertSame(1, $account->fresh()->stamp_count);

        $reversals = LoyaltyTransaction::query()
            ->where('order_id', $order->id)
            ->where('reason', 'reversed from void')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $reversals);
        $this->assertSame(50, $reversals[0]->points_delta);
        $this->assertSame(1, $reversals[0]->stamps_delta);
        $this->assertSame(50, $reversals[0]->balance_after_points);
        $this->assertSame(1, $reversals[0]->balance_after_stamps);
        $this->assertSame(-30, $reversals[1]->points_delta);
        $this->assertSame(0, $reversals[1]->stamps_delta);
        $this->assertSame(20, $reversals[1]->balance_after_points);
        $this->assertSame(1, $reversals[1]->balance_after_stamps);
        $this->assertSame(0, $marker->fresh()->points_delta);
        $this->assertSame(0, $marker->fresh()->stamps_delta);
        $this->assertDatabaseCount('pos_loyalty_transactions', 5);
    }

    public function test_a_preexisting_failed_over_redemption_heals_from_its_frozen_payload(): void
    {
        $this->seedLoyalty();
        $device = $this->device();
        $this->seedAccount(2, points: 30);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $pay = $this->payEvent($uuid, null, ['rule_id' => 2, 'points' => 50]);
        SyncEvent::create([
            'client_event_id' => $pay['client_event_id'],
            'device_id' => $device->id,
            'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
            'event_type' => 'order.pay',
            'payload_json' => $pay['payload'],
            'client_timestamp' => now(),
            'server_received_at' => now(),
            'processed_at' => now(),
            'ack_status' => SyncEvent::STATUS_FAILED,
            'result_json' => ['error' => 'Point balance cannot go negative.'],
        ]);

        $incoming = $pay;
        $incoming['payload']['loyalty_redeem']['points'] = 1;
        $res = $this->push('mdev_ord', [$incoming])->assertOk();

        $this->assertTrue($res->json('data.results.0.duplicate'));
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertSame(Order::STATUS_PAID, Order::firstWhere('uuid', $uuid)->status);
        $this->assertSame(0, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail()->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'type' => 'redeem',
            'points_delta' => -30,
        ]);
        $this->assertStringContainsString(
            'shortfall points=20 stamps=0',
            (string) LoyaltyTransaction::where('type', LoyaltyTransaction::TYPE_ADJUST)->firstOrFail()->reason,
        );
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);

        $again = $this->push('mdev_ord', [$incoming])->assertOk();
        $this->assertTrue($again->json('data.results.0.duplicate'));
        $this->assertSame('processed', $again->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
    }

    public function test_a_negative_existing_balance_is_not_treated_as_a_recoverable_shortfall(): void
    {
        $this->seedLoyalty();
        $this->device();
        $this->seedAccount(2, points: -5);
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        $res = $this->push('mdev_ord', [
            $this->payEvent($uuid, null, ['rule_id' => 2, 'points' => 1]),
        ])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString(
            'Cannot reconcile a negative loyalty account balance.',
            $res->json('data.results.0.result.error'),
        );
        $this->assertSame(Order::STATUS_OPEN, Order::firstWhere('uuid', $uuid)->status);
        $this->assertSame(-5, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail()->point_balance);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_multiple_earn_rules_all_accrue_in_one_pay(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        // v2 #3 — the device names BOTH active earn programs (stamp card rule 1
        // + points rule 2) for the customer in one pay; each must accrue.
        $pay = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
                'loyalty_rule_ids' => [1, 2],
            ],
        ];
        $res = $this->push('mdev_ord', [$pay])->assertOk();

        $this->assertCount(2, $res->json('data.results.0.result.loyalty_transaction_ids'));
        // Visit rule 1 → 1 stamp; spend rule 2 → 30 points (3.000 OMR × 10/OMR).
        $this->assertSame(1, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first()->stamp_count);
        $this->assertSame(30, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->first()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
    }

    public function test_legacy_single_loyalty_rule_id_still_earns(): void
    {
        $this->seedLoyalty();
        $this->device();
        $uuid = (string) Str::uuid();

        $this->push('mdev_ord', [$this->createEvent($uuid, 1)])->assertOk();
        // Back-compat: a device still sending the singular loyalty_rule_id works.
        $res = $this->push('mdev_ord', [$this->payEvent($uuid, 1)])->assertOk();

        $this->assertNotNull($res->json('data.results.0.result.loyalty_transaction_id'));
        $this->assertCount(1, $res->json('data.results.0.result.loyalty_transaction_ids'));
        $this->assertSame(1, LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 1])->first()->stamp_count);
    }
}
