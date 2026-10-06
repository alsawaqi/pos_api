<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\TableSessionEvent;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A items 3, 7 and 10 — the customer's points request (tester
 * call 9): stored on the tablet order, approved by staff with loyalty.redeem
 * (the position tick or an approver PIN proof) under the table redemption's
 * limits (3 per customer per day, 10 per approver per shift, balance less
 * reservations, a positive amount left), applied as the loyalty discount and
 * redeemed at pay; a rejection leaves the full amount to pay. Quick / To go
 * and Dine in.
 */
final class TabletRedeemTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    private int $rule;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
        $this->rule = $this->p6Rule();
        $this->customer = $this->p6Customer('+96891234567');
        $this->p6Account($this->customer, $this->rule, 250);
    }

    /** @return array<string, mixed> */
    private function requestPoints(int $blocks = 2, array $override = []): array
    {
        return $this->p6Submit($override + ['phone' => '91234567', 'payment' => 'points',
            'redeem_request' => ['rule_id' => $this->rule, 'blocks' => $blocks]])->assertCreated()->json('data');
    }

    private function approve(string $uuid, int $staffId, ?array $authorization, ?string $ref = null): TestResponse
    {
        $ref ??= (string) Str::uuid();

        return $this->p6Staff($this->till, $staffId, 'POST', "/api/v1/device/tablet-orders/{$uuid}/redeem/approve",
            ['client_request_id' => $ref, 'auth_v' => 1] + ($authorization === null ? [] : ['authorization' => $authorization]));
    }

    private function asManager(string $uuid): TestResponse
    {
        $ref = (string) Str::uuid();

        return $this->approve($uuid, 8, $this->p5Position('loyalty.redeem', 8, $ref), $ref);
    }

    private function balance(): int
    {
        return (int) DB::table('pos_loyalty_accounts')->where('customer_id', $this->customer)->where('loyalty_rule_id', $this->rule)->value('point_balance');
    }

    public function test_a_request_approved_within_the_limits_is_the_loyalty_discount_and_is_redeemed_at_pay(): void
    {
        $order = $this->requestPoints(2);
        $this->assertSame(['status' => 'requested', 'rule_id' => $this->rule, 'blocks' => 2], $order['redeem']);
        $row = collect($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'))->sole();
        $this->assertSame(['requested', 'points', 200, 1000, '9xxx4567'], [$row['redeem']['status'], $row['redeem']['kind'],
            $row['redeem']['units'], $row['redeem']['amount_baisas'], $row['phone_masked']]);

        // A cashier has no loyalty.redeem tick: an approver is needed.
        $this->approve($order['tablet_order_uuid'], 7, null)->assertForbidden()->assertJsonPath('errors.0.code', 'approval_required');
        $this->assertSame(TabletOrder::REDEEM_REQUESTED, $this->p6Row($order['tablet_order_uuid'])->redeem_status);
        // The manager's own tick approves it (a cashier's take is not needed: the
        // manager takes the untaken order).
        $this->asManager($order['tablet_order_uuid'])->assertOk()->assertJsonPath('data.outcome', 'approved')
            ->assertJsonPath('data.order.redeem.status', 'approved')->assertJsonPath('data.order.redeem.resolved_by.name', 'Manager 8');
        $held = $this->p6Order($order['order_uuid']);
        $this->assertSame(['2.000', '1.000', '1.000'], [$held->subtotal, $held->discount_total, $held->grand_total]);
        $this->assertSame(['table_loyalty_redeem', '1.000'], [OrderDiscount::query()->sole()->amount_type_snapshot, OrderDiscount::query()->sole()->amount]);
        // The approved units are reserved: nothing more is redeemable until it is paid.
        $this->assertSame(0, $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])
            ->json('data.accounts.0.redeemable_blocks'));
        $this->asManager($order['tablet_order_uuid'])->assertOk()->assertJsonPath('data.outcome', 'replayed');

        $this->p6PayCash($this->till, $order['order_uuid'], 1000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(1, LoyaltyTransaction::query()->where('type', 'redeem')->count());
        // 250 − 200 redeemed + 10 earned on the 1.000 paid.
        $this->assertSame(60, $this->balance());
    }

    public function test_an_approver_pin_proof_approves_it_for_a_cashier(): void
    {
        $order = $this->requestPoints(1);
        $ref = (string) Str::uuid();
        $block = $this->p5Approval($this->till, 'loyalty.redeem', 8, 7, $order['tablet_order_uuid'], 500, $ref,
            now()->subMinute()->utc()->format('Y-m-d\TH:i:s.v\Z'));

        $this->approve($order['tablet_order_uuid'], 7, $block, $ref)->assertOk()->assertJsonPath('data.order.redeem.resolved_by.staff_id', 8);
        $this->assertSame('1.500', $this->p6Order($order['order_uuid'])->grand_total);
        $approval = DB::table('pos_approvals')->where('result', 'verified')->sole();
        $this->assertSame(['loyalty.redeem', 'tablet_order', $order['tablet_order_uuid'], 8], [$approval->action, $approval->subject_type,
            $approval->subject_uuid, (int) $approval->approver_staff_id]);
        // A proof made for another amount never approves.
        $other = $this->requestPoints(1);
        $ref2 = (string) Str::uuid();
        $this->approve($other['tablet_order_uuid'], 7, $this->p5Approval($this->till, 'loyalty.redeem', 8, 7, $other['tablet_order_uuid'], 999, $ref2), $ref2)
            ->assertForbidden()->assertJsonPath('errors.0.code', 'approval_invalid');
    }

    public function test_requests_over_the_balance_the_customer_day_or_the_staff_shift_are_refused(): void
    {
        // At submit: more than the balance, or the whole order, is refused.
        $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 3]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_available');
        $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 2],
            'lines' => [$this->p6Line($this->coffee)]])->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_available');
        $this->p6Submit(['payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_available');
        $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->p6Rule(200), 'blocks' => 1]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_available');

        // At approval: the balance has gone since.
        $order = $this->requestPoints(2);
        $this->p6Account($this->customer, $this->rule, 150);
        $this->asManager($order['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'loyalty_insufficient');
        $this->assertSame('2.000', $this->p6Order($order['order_uuid'])->grand_total);

        // 3 per customer per day.
        $this->p6Account($this->customer, $this->rule, 10000);
        for ($i = 0; $i < 3; $i++) {
            $this->asManager($this->requestPoints(1)['tablet_order_uuid'])->assertOk();
        }
        $this->asManager($this->requestPoints(1)['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'loyalty_customer_limit');

        // 10 per approver per shift (day): ten other customers' approvals by staff 9.
        for ($i = 0; $i < 10; $i++) {
            $orderId = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'order_type' => 'quick', 'status' => 'held', 'source' => 'customer_tablet', 'subtotal' => 1, 'grand_total' => 1,
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            // Fix order 2 (F-12) — each approval still holds its points slot.
            $slot = (int) DB::table('pos_order_discounts')->insertGetId(['company_id' => 100, 'branch_id' => 10, 'order_id' => $orderId,
                'name_snapshot' => 'Coffee points', 'amount_type_snapshot' => 'table_loyalty_redeem', 'amount' => '0.500',
                'reason' => 'points × 100 — rule '.$this->rule, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_tablet_orders')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'client_uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'order_type' => 'quick', 'customer_id' => 900 + $i,
                'subtotal_baisas' => 1000, 'tax_baisas' => 0, 'total_baisas' => 1000, 'redeem_status' => 'approved',
                'redeem_rule_id' => $this->rule, 'redeem_blocks' => 1, 'redeem_resolved_by_staff_id' => 9, 'redeem_resolved_at' => now(),
                'redeem_discount_row_id' => $slot, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $fresh = $this->p6Customer('+96899000000', 'Fresh');
        $this->p6Account($fresh, $this->rule, 500);
        $next = $this->p6Submit(['phone' => '99000000', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])
            ->assertCreated()->json('data');
        $ref = (string) Str::uuid();
        $this->approve($next['tablet_order_uuid'], 9, $this->p5Position('loyalty.redeem', 9, $ref), $ref)->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'loyalty_staff_limit');
        // A refusal changes nothing (not even the take): another approver may still approve it.
        $this->asManager($next['tablet_order_uuid'])->assertOk()->assertJsonPath('data.order.redeem.resolved_by.staff_id', 8);
    }

    public function test_a_rejected_request_leaves_the_full_amount_to_pay_in_cash(): void
    {
        $order = $this->requestPoints(2);

        $this->p6Staff($this->handheld, 7, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/redeem/reject")->assertOk()
            ->assertJsonPath('data.order.redeem.status', 'rejected')->assertJsonPath('data.order.redeem.resolved_by.name', 'Cashier 7');
        $this->asManager($order['tablet_order_uuid'])->assertStatus(409);
        $this->assertSame(['2.000', '0.000'], [$this->p6Order($order['order_uuid'])->grand_total, $this->p6Order($order['order_uuid'])->discount_total]);

        $this->p6PayCash($this->till, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(0, LoyaltyTransaction::query()->where('type', 'redeem')->count());
        $this->assertSame(270, $this->balance());
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$this->p6Submit()->json('data.tablet_order_uuid')}/redeem/reject")
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_requested');
    }

    public function test_a_dine_in_request_is_approved_on_the_table_bill_once_the_round_is_sent(): void
    {
        $table = $this->seatingTable('Table 5');
        $order = $this->requestPoints(1, ['order_type' => 'dine_in', 'table_uuid' => $table->uuid]);

        $this->asManager($order['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_round_not_sent');
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $this->asManager($order['tablet_order_uuid'])->assertOk()->assertJsonPath('data.order.redeem.status', 'approved');

        $bill = $this->p6Order($order['order_uuid']);
        $this->assertSame(['2.000', '1.500', 'customer_tablet'], [$bill->subtotal, $bill->grand_total, $bill->source]);
        $event = TableSessionEvent::query()->where('event_type', 'adjusted')->sole();
        $this->assertSame(['loyalty', 'redeem', $this->customer, 8, 'customer_tablet'], [$event->payload['kind'], $event->payload['mode'],
            $event->payload['customer_id'], $event->payload['approved_by_staff_id'], $event->payload['origin']]);

        $this->p6PayCash($this->till, $bill->uuid, 1500)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(Order::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame(1, LoyaltyTransaction::query()->where('type', 'redeem')->count());
        $this->assertSame(250 - 100 + 15, $this->balance());
    }
}
