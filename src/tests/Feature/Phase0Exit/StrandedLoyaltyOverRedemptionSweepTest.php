<?php

declare(strict_types=1);

namespace Tests\Feature\Phase0Exit;

use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 0 exit — W-A1 / EXIT-04 (sweep path).
 *
 * An order.pay carrying an over-balance loyalty_redeem is stranded at
 * STATUS_RECEIVED (its request worker died after ingest). The recovery
 * sweep (`sync:sweep-stranded-events`, run with a valid quarantine floor)
 * must heal it from the FROZEN ledger payload exactly once:
 *
 *   - the sale settles fully (paid order, payment row, stock movement,
 *     commission split),
 *   - the loyalty debit clamps to the locked available balance,
 *   - exactly ONE zero-delta shortfall ADJUST marker is appended,
 *   - a second sweep is a complete no-op.
 *
 * The push-path twin of this scenario is pinned in DeviceSyncLoyaltyTest
 * (test_over_balance_redemption_settles_every_sale_effect_and_flags_the_
 * shortfall); this file proves the same reconciliation holds when recovery
 * — not the live request — performs the settlement.
 */
class StrandedLoyaltyOverRedemptionSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);

        // This suite creates a fresh ledger with no historical quarantine;
        // the flag gates only the schedule, never a manual run.
        config([
            'sync.stranded_sweep_after_id' => 0,
            'sync.stranded_sweep_enabled' => false,
        ]);
    }

    private function device(): Device
    {
        $device = Device::factory()->paired('mdev_p0_swp')->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
        // Assigned well before the stranded event's server receipt so the
        // sweep's reassignment guard accepts the attribution.
        $device->forceFill(['assigned_at' => now()->subHour()])->save();

        return $device;
    }

    /** Catalogue + customer + loyalty rules (mirrors DeviceSyncLoyaltyTest). */
    private function seedLoyalty(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 8.000, 'status' => 'active'] + $t,
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
            'point_balance' => 30,
            'stamp_count' => 0,
        ] + $t);
    }

    /** Recipe + branch stock + commission profile so the sale's FULL settlement is observable. */
    private function seedSettlementEffects(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        // LAUNCH-P3 P3-7: the recipe product is made-to-order (an untracked
        // product never deducts a leftover recipe).
        DB::table('pos_products')->where('id', 1)->update(['stock_mode' => 'ingredient']);
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

    public function test_a_stranded_over_redemption_pay_heals_from_its_frozen_payload_exactly_once(): void
    {
        $this->seedLoyalty();
        $this->seedSettlementEffects();
        $device = $this->device();
        $uuid = (string) Str::uuid();

        // The order itself arrived normally (push path, processed inline):
        // 8.000 subtotal − 5.000 loyalty-redemption discount = 3.000 due.
        $this->withToken('mdev_p0_swp')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $uuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => 7,
                'customer_id' => 1,
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 8000,
                'discount_total_baisas' => 5000,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'discounts' => [['name' => 'Loyalty redemption', 'amount_baisas' => 5000]],
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 8000, 'line_discount_baisas' => 0, 'line_total_baisas' => 8000]],
            ]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // The matching order.pay reached the durable ledger, then its worker
        // died before the handler ran — stranded at STATUS_RECEIVED with the
        // device's optimistic 100-point spend FROZEN in payload_json. The
        // server balance only holds 30.
        $stranded = SyncEvent::create([
            'client_event_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
            'event_type' => 'order.pay',
            'payload_json' => [
                'order_uuid' => $uuid,
                'paid_at' => now()->subMinutes(12)->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
                'loyalty_redeem' => ['rule_id' => 2, 'points' => 100],
            ],
            'client_timestamp' => now()->subMinutes(12),
            'server_received_at' => now()->subMinutes(11),
            'ack_status' => SyncEvent::STATUS_RECEIVED,
        ]);

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=0 skipped=0')
            ->assertSuccessful();

        // The recovery run settled the event with the push path's full ACK.
        $stranded->refresh();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $stranded->ack_status);
        $this->assertNotNull($stranded->processed_at);
        $this->assertSame('paid', $stranded->result_json['status']);
        $this->assertCount(1, $stranded->result_json['payment_ids']);
        $this->assertSame(1, (int) $stranded->result_json['movements']);
        $this->assertCount(2, $stranded->result_json['sale_commission_ids']);
        $this->assertNotNull($stranded->result_json['loyalty_redeem_transaction_id']);
        $this->assertNotNull($stranded->result_json['loyalty_redeem_adjustment_id']);
        $this->assertStringContainsString(
            '[LOYALTY_REDEMPTION_SHORTFALL][REVIEW_REQUIRED]',
            (string) $stranded->result_json['loyalty_redeem_warning'],
        );

        // The sale settled fully.
        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseHas('pos_payments', ['order_id' => $order->id, 'method' => 'cash', 'status' => 'success']);
        $this->assertEqualsWithDelta(3.000, (float) DB::table('pos_payments')->value('amount'), 1e-9);
        $this->assertEqualsWithDelta(
            4.750,
            (float) DB::table('pos_branch_stock')->where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'),
            1e-9,
        );
        $this->assertDatabaseCount('pos_stock_movements', 1);
        $this->assertEqualsWithDelta(
            3.000,
            (float) DB::table('pos_sale_commissions')->where('order_id', $order->id)->sum('commission_amount'),
            1e-9,
        );

        // The debit clamped to the locked balance: −30, never −100.
        $account = LoyaltyAccount::where(['customer_id' => 1, 'loyalty_rule_id' => 2])->firstOrFail();
        $this->assertSame(0, $account->point_balance);
        $this->assertDatabaseHas('pos_loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'points_delta' => -30,
            'balance_after_points' => 0,
            'order_id' => $order->id,
        ]);

        // Exactly one immutable zero-delta shortfall ADJUST marker.
        $markers = LoyaltyTransaction::query()
            ->where('loyalty_account_id', $account->id)
            ->where('type', LoyaltyTransaction::TYPE_ADJUST)
            ->get();
        $this->assertCount(1, $markers);
        $marker = $markers->first();
        $this->assertSame(0, $marker->points_delta);
        $this->assertSame(0, $marker->stamps_delta);
        $this->assertSame(0, $marker->balance_after_points);
        $this->assertSame((int) $order->id, (int) $marker->order_id);
        $this->assertStringContainsString('[LOYALTY_REDEMPTION_SHORTFALL][REVIEW_REQUIRED]', (string) $marker->reason);
        $this->assertStringContainsString('requested points=100 stamps=0', (string) $marker->reason);
        $this->assertStringContainsString('applied points=30 stamps=0', (string) $marker->reason);
        $this->assertStringContainsString('shortfall points=70 stamps=0', (string) $marker->reason);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2); // redeem + marker, nothing else

        // A second sweep is a complete no-op: the event is terminal and no
        // effect happens twice.
        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=0 failed=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_PROCESSED, $stranded->fresh()->ack_status);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_stock_movements', 1);
        $this->assertDatabaseCount('pos_sale_commissions', 2);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
        $this->assertSame(0, $account->fresh()->point_balance);
        $this->assertSame(
            1,
            LoyaltyTransaction::query()
                ->where('reason', 'like', '[LOYALTY_REDEMPTION_SHORTFALL]%')
                ->count(),
        );
        $this->assertEqualsWithDelta(
            4.750,
            (float) DB::table('pos_branch_stock')->where(['branch_id' => 10, 'ingredient_id' => 1])->value('quantity'),
            1e-9,
        );
    }
}
