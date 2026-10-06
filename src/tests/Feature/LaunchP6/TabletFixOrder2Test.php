<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TabletOrder;
use App\Models\TabletOrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 2 (LAUNCH-P6_A_FIX_ORDER_2.md) — F-9 to F-12.
 */
final class TabletFixOrder2Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    private function openShift(string $token, int $staffId): string
    {
        $uuid = (string) Str::uuid();
        $this->p5Push($token, [$this->p5Event('shift.open', ['uuid' => $uuid, 'staff_id' => $staffId, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHour()->toIso8601String()], at: now()->subHour()->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        return $uuid;
    }

    /** @return array<string, mixed> the close result */
    private function closeShift(string $token, string $shift, int $staffId, int $closing): array
    {
        $result = $this->p5Push($token, [$this->p5Event('shift.close', ['shift_uuid' => $shift, 'closing_cash_baisas' => $closing,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => $staffId, 'order_uuids' => [], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$shift)->toString())])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $result['status'], json_encode($result));

        return $result['result'];
    }

    /** @return list<int> expected cash, variance, order count */
    private function z(array $close): array
    {
        return [$close['expected_cash_baisas'], $close['variance_baisas'], $close['summary']['order_count']];
    }

    private function qrQuickOrder(): Order
    {
        return Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'source' => Order::SOURCE_QR_WEB, 'order_type' => 'quick', 'status' => Order::STATUS_HELD,
            'subtotal' => '3.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '3.000', 'opened_at' => now()->subMinutes(5), 'temp_reference' => 'T-1006-098']);
    }

    // ---- F-9 — the payer's shared shift first, then the paying device's, never both ----

    public function test_f9_cash_follows_the_payers_shared_shift_even_when_the_paying_device_has_its_own(): void
    {
        $tillShift = $this->openShift('mdev_p6_till', 7);
        $handheldShift = $this->openShift('mdev_p6_handheld', 9);
        // Staff 7 (shift on T) takes a tablet order and a QR order on H.
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $qr = $this->qrQuickOrder();
        $this->p6PayCash($this->handheld, $qr->uuid, 3000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        // Staff 9 (shift on H) takes a tablet order on T.
        $other = $this->p6Submit(['lines' => [$this->p6Line($this->cake)]])->assertCreated()->json('data');
        $this->p6PayCash($this->till, $other['order_uuid'], 2000, 9)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $till = $this->closeShift('mdev_p6_till', $tillShift, 7, 10000);
        $handheld = $this->closeShift('mdev_p6_handheld', $handheldShift, 9, 7000);

        $this->assertSame([10000, 0, 2], $this->z($till));
        $this->assertSame([7000, 0, 1], $this->z($handheld));
        // Three payments, three order counts: none counted twice.
        $this->assertSame(3, $till['summary']['order_count'] + $handheld['summary']['order_count']);
    }

    public function test_f9_an_unknown_payer_or_a_payer_without_a_shared_shift_leaves_the_cash_with_the_paying_device(): void
    {
        $tillShift = $this->openShift('mdev_p6_till', 7);
        $handheldShift = $this->openShift('mdev_p6_handheld', 9);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 999)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $qr = $this->qrQuickOrder();
        // The manager (8) has no shift open.
        $this->p6PayCash($this->handheld, $qr->uuid, 3000, 8)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame([null, 8], DB::table('pos_payments')->orderBy('id')->pluck('staff_id')
            ->map(fn ($v) => $v === null ? null : (int) $v)->all());

        $till = $this->closeShift('mdev_p6_till', $tillShift, 7, 5000);
        $handheld = $this->closeShift('mdev_p6_handheld', $handheldShift, 9, 10000);

        $this->assertSame([5000, 0, 0], $this->z($till));
        $this->assertSame([10000, 0, 2], $this->z($handheld));
    }

    public function test_f9_a_staff_opened_table_bill_with_a_tablet_round_goes_to_the_payers_shift(): void
    {
        $tillShift = $this->openShift('mdev_p6_till', 7);
        $handheldShift = $this->openShift('mdev_p6_handheld', 9);
        // Staff 9 seats the table on H and enters a cake.
        $table = $this->seatingTable('Table 9');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $this->handheld->id]);
        $id = (string) Str::uuid();
        $this->p6As($this->handheld, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(), 'staff_id' => 9,
                'lines' => [['product_id' => $this->cake, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $bill = Order::query()->sole();
        $this->assertSame([(int) $this->handheld->id, 'dine_in'], [(int) $bill->device_id, $bill->order_type]);
        // The customer adds two coffees from the tablet; staff 9 sends them.
        $round = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');
        $this->assertSame($bill->uuid, $round['order_uuid']);
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$round['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $this->assertSame('4.000', $bill->fresh()->grand_total);

        // Staff 7 takes the whole bill's cash on H.
        $this->p6As($this->handheld, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $bill->uuid])->assertOk();
        $this->p6PayCash($this->handheld, $bill->uuid, 4000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $till = $this->closeShift('mdev_p6_till', $tillShift, 7, 9000);
        $handheld = $this->closeShift('mdev_p6_handheld', $handheldShift, 9, 5000);

        $this->assertSame([9000, 0, 1], $this->z($till));
        $this->assertSame([5000, 0, 0], $this->z($handheld));
    }

    // ---- F-10 — a tablet Quick / To go order is claimed before its cash is taken ----

    private function edit(string $uuid, array $lines, ?Device $device = null): TestResponse
    {
        return $this->p6Staff($device ?? $this->till, 7, 'PUT', "/api/v1/device/tablet-orders/{$uuid}/lines",
            ['client_request_id' => (string) Str::uuid(), 'lines' => $lines]);
    }

    private function claim(string $orderUuid, ?Device $device = null): TestResponse
    {
        return $this->p6Staff($device ?? $this->till, 7, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $orderUuid]);
    }

    public function test_f10_a_claimed_tablet_order_cannot_be_edited_until_the_claim_is_released_then_it_pays(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $claim = $this->claim($order['order_uuid'])->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000)->json('data');
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->p6Order($order['order_uuid'])->status);
        $this->p6As($this->till, 'GET', "/api/v1/device/qr/orders/{$order['order_uuid']}/checkout")->assertOk()
            ->assertJsonPath('data.claim.charge_amount_baisas', 2000);

        $this->edit($order['tablet_order_uuid'], [$this->p6Line($this->coffee, 3)])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_being_paid')
            ->assertJsonPath('errors.0.message', 'Staff are taking the payment for this order; cancel the payment first.');
        $this->assertSame('2.000', $this->p6Order($order['order_uuid'])->grand_total);
        $this->assertSame(0, TabletOrderEvent::query()->where('event_type', 'edited')->count());
        // Another device cannot take the cash while the till holds the claim.
        $this->assertSame('failed', $this->p6PayCash($this->handheld, $order['order_uuid'], 2000)->json('data.results.0.status'));

        // Back: the claim is released and the order may change again.
        $this->p6As($this->till, 'POST', '/api/v1/device/qr/cancel-settlement',
            array_intersect_key($claim, array_flip(['order_uuid', 'charge_claimed_at', 'charge_deadline_at'])))
            ->assertOk()->assertJsonPath('data.status', 'held');
        $this->edit($order['tablet_order_uuid'], [$this->p6Line($this->coffee, 3)])->assertOk()->assertJsonPath('data.outcome', 'edited')
            ->assertJsonPath('data.order.grand_total_baisas', 3000);

        // Claim again at the new amount, then pay.
        $this->claim($order['order_uuid'])->assertOk()->assertJsonPath('data.charge_amount_baisas', 3000);
        $this->p6PayCash($this->till, $order['order_uuid'], 3000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(Order::STATUS_PAID, $this->p6Order($order['order_uuid'])->status);
        $this->edit($order['tablet_order_uuid'], [$this->p6Line($this->coffee)])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_paid');
    }

    public function test_f10_a_to_go_tablet_order_is_claimed_and_paid_on_a_handheld(): void
    {
        $order = $this->p6Submit(['order_type' => 'to_go'])->assertCreated()->json('data');
        $this->claim($order['order_uuid'], $this->handheld)->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000);
        $this->edit($order['tablet_order_uuid'], [$this->p6Line($this->cake)], $this->handheld)->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_being_paid');

        $this->p6PayCash($this->handheld, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertSame(Order::STATUS_PAID, $this->p6Order($order['order_uuid'])->status);
    }

    public function test_f10_the_claim_keeps_tenancy_and_never_takes_an_order_merely_labelled_tablet(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        foreach ([$this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20), $this->p6Device('mdev_p6_b11', 'fixed_pos', 100, 11)] as $device) {
            $this->claim($order['order_uuid'], $device)->assertNotFound()->assertJsonPath('errors.0.code', 'order_not_found');
            $this->p6As($device, 'GET', "/api/v1/device/qr/orders/{$order['order_uuid']}/checkout")->assertNotFound();
        }
        $this->assertSame(Order::STATUS_HELD, $this->p6Order($order['order_uuid'])->status);

        $labelled = Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->till->id, 'source' => 'customer_tablet', 'order_type' => 'quick', 'status' => Order::STATUS_HELD,
            'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '1.000', 'opened_at' => now(), 'temp_reference' => 'T-1006-097']);
        $this->claim($labelled->uuid)->assertStatus(409)->assertJsonPath('errors.0.code', 'qr_order_not_settleable');
        $this->assertSame(Order::STATUS_HELD, $labelled->fresh()->status);
    }

    // ---- F-11 — a dine-in edit re-checks the round under its lock ----

    public function test_f11_a_round_rejected_between_the_read_and_the_lock_refuses_the_edit(): void
    {
        $table = $this->seatingTable('Table 4');
        $order = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');
        $round = QrOrderRound::query()->sole();
        $before = $round->priced_lines;
        // After the edit has read the round (unlocked) and while it prices the
        // new lines, staff reject the round on another device.
        $readRound = false;
        $rejected = false;
        DB::listen(function ($query) use (&$readRound, &$rejected, $round): void {
            $sql = strtolower($query->sql);
            if (! $readRound && str_starts_with($sql, 'select') && str_contains($sql, 'from "pos_qr_order_rounds"')) {
                $readRound = true;

                return;
            }
            if ($readRound && ! $rejected && str_contains($sql, '"pos_products"')) {
                $rejected = true;
                DB::table('pos_qr_order_rounds')->where('id', $round->id)->update(['status' => QrOrderRound::STATUS_REJECTED]);
            }
        });

        $this->edit($order['tablet_order_uuid'], [$this->p6Line($this->cake, 2)])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_closed');

        $this->assertTrue($rejected);
        $this->assertSame(0, TabletOrderEvent::query()->where('event_type', 'edited')->count());
        // (The simulated rejection ran on the request's own connection, so the
        // refusal rolled it back too; what matters is that nothing was edited.)
        $this->assertSame($before, $round->fresh()->priced_lines);
    }

    // ---- F-12 — the limits count an approval only while its points slot exists ----

    private int $rule;

    private function approve(string $uuid): TestResponse
    {
        $ref = (string) Str::uuid();

        return $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$uuid}/redeem/approve",
            ['client_request_id' => $ref, 'auth_v' => 1, 'authorization' => $this->p5Position('loyalty.redeem', 8, $ref)]);
    }

    /** @return array<string, mixed> a Quick order asking for one block of points */
    private function pointsOrder(string $phone = '91234567'): array
    {
        return $this->p6Submit(['phone' => $phone, 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])
            ->assertCreated()->json('data');
    }

    public function test_f12_an_approval_whose_slot_was_removed_without_the_callback_does_not_count_toward_the_customers_day(): void
    {
        $this->rule = $this->p6Rule();
        $this->p6Account($this->p6Customer('+96891234567'), $this->rule, 5000);
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $orders[] = $this->pointsOrder();
            $this->approve($orders[$i]['tablet_order_uuid'])->assertOk();
        }
        // The first slot is cleared by a writer whose "superseded" callback never ran.
        $stale = $this->p6Row($orders[0]['tablet_order_uuid']);
        $slot = DB::table('pos_order_discounts')->where('id', $stale->redeem_discount_row_id)->first();
        DB::table('pos_order_discounts')->insert(['company_id' => 100, 'branch_id' => 10, 'order_id' => $slot->order_id,
            'name_snapshot' => $slot->name_snapshot, 'amount_type_snapshot' => 'table_loyalty_reversal', 'amount' => '-0.500',
            'reason' => $slot->reason, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(TabletOrder::REDEEM_APPROVED, $stale->fresh()->redeem_status);

        $this->approve($this->pointsOrder()['tablet_order_uuid'])->assertOk()->assertJsonPath('data.order.redeem.status', 'approved');
        // Three live approvals again: the next is refused.
        $this->approve($this->pointsOrder()['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'loyalty_customer_limit');
    }

    public function test_f12_an_approval_without_a_live_slot_does_not_count_toward_the_staff_limit(): void
    {
        $this->rule = $this->p6Rule();
        $this->p6Account($this->p6Customer('+96891234567'), $this->rule, 5000);
        // Ten approvals by the manager whose slots no longer exist.
        for ($i = 0; $i < 10; $i++) {
            $orderId = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'order_type' => 'quick', 'status' => 'held', 'source' => 'customer_tablet', 'subtotal' => 1, 'grand_total' => 1,
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_tablet_orders')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'client_uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'order_type' => 'quick', 'customer_id' => 900 + $i,
                'subtotal_baisas' => 1000, 'tax_baisas' => 0, 'total_baisas' => 1000, 'redeem_status' => 'approved',
                'redeem_rule_id' => $this->rule, 'redeem_blocks' => 1, 'redeem_resolved_by_staff_id' => 8, 'redeem_resolved_at' => now(),
                'redeem_discount_row_id' => null, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->approve($this->pointsOrder()['tablet_order_uuid'])->assertOk()->assertJsonPath('data.order.redeem.status', 'approved');
    }
}
