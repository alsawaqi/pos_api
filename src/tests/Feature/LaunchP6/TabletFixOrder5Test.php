<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TabletOrder;
use App\Models\TabletOrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 5 (LAUNCH-P6_A_FIX_ORDER_5.md) — F-18 (no new
 * claim or pay while a points request is open) and F-19 (the claim applies
 * the taker rule, with the staff token; the tablet row is locked first).
 */
final class TabletFixOrder5Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    private int $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    private function claim(string $orderUuid, ?Device $device = null, ?int $staffId = 7): TestResponse
    {
        $device ??= $this->till;

        return $staffId === null
            ? $this->p6As($device, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $orderUuid])
            : $this->p6Staff($device, $staffId, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $orderUuid]);
    }

    /** @return array<string, mixed> the sync result */
    private function pay(Device $device, string $orderUuid, int $amount): array
    {
        return $this->p6PayCash($device, $orderUuid, $amount)->assertOk()->json('data.results.0');
    }

    private function points(): void
    {
        $this->rule = $this->p6Rule();
        $this->p6Account($this->p6Customer('+96891234567'), $this->rule, 5000);
    }

    /** @return array<string, mixed> */
    private function pointsOrder(array $override = []): array
    {
        return $this->p6Submit($override + ['phone' => '91234567', 'payment' => 'points',
            'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])->assertCreated()->json('data');
    }

    private function approve(string $uuid): TestResponse
    {
        $ref = (string) Str::uuid();

        return $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$uuid}/redeem/approve",
            ['client_request_id' => $ref, 'auth_v' => 1, 'authorization' => $this->p5Position('loyalty.redeem', 8, $ref)]);
    }

    private function untouched(string $orderUuid): void
    {
        $order = $this->p6Order($orderUuid);
        $this->assertSame([Order::STATUS_HELD, null, null], [$order->status, $order->charge_claimed_at, $order->charge_device_id]);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
    }

    // ---- F-18 ----

    public function test_f18_claim_and_pay_wait_for_the_points_answer_then_work_after_approve_or_reject(): void
    {
        $this->points();
        $approved = $this->pointsOrder();
        $rejected = $this->pointsOrder();

        foreach ([$approved, $rejected] as $order) {
            $this->claim($order['order_uuid'], null, 8)->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_pending')
                ->assertJsonPath('errors.0.message', 'Answer the points request first.');
            $pay = $this->pay($this->till, $order['order_uuid'], 2000);
            $this->assertSame(['failed', 'redeem_pending'], [$pay['status'], $pay['result']['code'] ?? null]);
            $this->untouched($order['order_uuid']);
            $this->assertNull($this->p6Row($order['tablet_order_uuid'])->taken_by_staff_id);
        }

        // Approved: the claim freezes the amount left after the points.
        $this->approve($approved['tablet_order_uuid'])->assertOk();
        $this->claim($approved['order_uuid'], null, 8)->assertOk()->assertJsonPath('data.charge_amount_baisas', 1500);
        $this->assertSame('processed', $this->pay($this->till, $approved['order_uuid'], 1500)['status']);
        // Rejected: the full amount.
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$rejected['tablet_order_uuid']}/redeem/reject")->assertOk();
        $this->claim($rejected['order_uuid'], null, 8)->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000);
        $this->assertSame('processed', $this->pay($this->till, $rejected['order_uuid'], 2000)['status']);
    }

    public function test_f18_a_table_bill_holding_a_tablet_round_with_an_open_points_request_waits_too(): void
    {
        $this->points();
        // Staff 9 seats the table on the handheld and enters a cake.
        $table = $this->seatingTable('Table 9');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $this->handheld->id]);
        $id = (string) Str::uuid();
        $this->p6As($this->handheld, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(), 'staff_id' => 9,
                'lines' => [['product_id' => $this->cake, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $round = $this->pointsOrder(['order_type' => 'dine_in', 'table_uuid' => $table->uuid]);
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$round['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $bill = $this->p6Order($round['order_uuid']);

        $this->claim($bill->uuid, $this->handheld, 9)->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_pending');
        $pay = $this->pay($this->handheld, $bill->uuid, 4000);
        $this->assertSame(['failed', 'redeem_pending'], [$pay['status'], $pay['result']['code'] ?? null]);
        $this->assertSame([Order::STATUS_OPEN, null], [$bill->fresh()->status, $bill->fresh()->charge_claimed_at]);

        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$round['tablet_order_uuid']}/redeem/reject")->assertOk();
        $this->claim($bill->uuid, $this->handheld, 9)->assertOk()->assertJsonPath('data.charge_amount_baisas', 4000);
        $this->assertSame('processed', $this->pay($this->handheld, $bill->uuid, 4000)['status']);
    }

    public function test_f18_cash_already_taken_under_a_claim_is_still_recovered_and_recorded_once(): void
    {
        $this->points();
        $manager = $this->p6Device('mdev_p6_till2', 'fixed_pos');
        $orders = [];
        foreach (['late', 'fallback', 'review'] as $path) {
            // Claimed before the points request was open (an order claimed by
            // a build before this fix): its claim stands.
            $order = $this->p6Submit()->assertCreated()->json('data');
            $this->claim($order['order_uuid'], $this->handheld, 7)->assertOk();
            TabletOrder::query()->where('uuid', $order['tablet_order_uuid'])->update(['redeem_status' => TabletOrder::REDEEM_REQUESTED,
                'redeem_rule_id' => $this->rule, 'redeem_blocks' => 1]);
            $orders[$path] = $order['order_uuid'];
        }
        $this->travel(6)->minutes();
        Artisan::call('qr:sweep-stale-charges');

        // The holder's late pay.
        $this->assertSame('processed', $this->pay($this->handheld, $orders['late'], 2000)['status']);
        // The counter fallback, then the attended pay.
        $this->p6As($this->till, 'POST', '/api/v1/device/qr/fallback-to-counter', ['order_uuid' => $orders['fallback']])->assertOk();
        $this->assertSame('processed', $this->pay($this->till, $orders['fallback'], 2000)['status']);
        // The manager payment review.
        $this->p6As($manager, 'POST', "/api/v1/device/qr/pending-orders/{$orders['review']}/payment-review", [
            'client_request_id' => (string) Str::uuid(), 'decision' => 'paid', 'reference' => 'Drawer count', 'pin' => '800008',
            'method' => 'cash', 'amount_baisas' => 2000])->assertOk()->assertJsonPath('data.status', Order::STATUS_PAID);

        foreach ($orders as $path => $uuid) {
            $this->assertSame([Order::STATUS_PAID, 1], [$this->p6Order($uuid)->status,
                Payment::query()->where('order_id', $this->p6Order($uuid)->id)->count()], $path);
        }
    }

    public function test_f18_and_f19_leave_qr_web_unchanged_no_staff_token_and_no_taker(): void
    {
        $qr = Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'source' => Order::SOURCE_QR_WEB, 'order_type' => 'quick', 'status' => Order::STATUS_HELD,
            'subtotal' => '2.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '2.000', 'opened_at' => now()->subMinutes(5), 'temp_reference' => 'T-1006-091']);

        $this->claim($qr->uuid, $this->handheld, null)->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000);
        $this->assertSame('processed', $this->pay($this->handheld, $qr->uuid, 2000)['status']);
        $this->assertSame(Order::STATUS_PAID, $qr->fresh()->status);
        $this->assertSame(0, TabletOrderEvent::query()->count());
    }

    // ---- F-19 ----

    public function test_f19_an_untaken_order_is_taken_by_the_claimer_and_a_claim_needs_the_staff_token(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');

        $this->claim($order['order_uuid'], null, null)->assertForbidden()->assertJsonPath('errors.0.code', 'staff_unverified')
            ->assertJsonPath('data.reason', 'token_missing');
        $this->untouched($order['order_uuid']);
        $this->assertNull($this->p6Row($order['tablet_order_uuid'])->taken_by_staff_id);

        $this->claim($order['order_uuid'])->assertOk();
        $row = $this->p6Row($order['tablet_order_uuid']);
        $this->assertSame([7, (int) $this->till->id], [(int) $row->taken_by_staff_id, (int) $row->taken_by_device_id]);
        $this->assertSame(1, TabletOrderEvent::query()->where('tablet_order_id', $row->id)->where('event_type', 'taken')->count());
        // A replay by the holder answers the same claim.
        $this->claim($order['order_uuid'])->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
    }

    public function test_f19_another_members_order_is_refused_and_take_over_then_claim_succeeds(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $uuid = $order['tablet_order_uuid'];
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertOk();

        $this->claim($order['order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_taken')
            ->assertJsonPath('data.taken_by.staff_id', 9)->assertJsonPath('data.taken_by.name', 'Supervisor 9');
        $this->untouched($order['order_uuid']);

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take", ['take_over' => true])->assertOk();
        $this->claim($order['order_uuid'])->assertOk();
        $this->assertSame(7, (int) $this->p6Row($uuid)->taken_by_staff_id);
        $this->assertSame('processed', $this->pay($this->till, $order['order_uuid'], 2000)['status']);
    }

    public function test_f19_the_claim_locks_the_tablet_row_before_the_order(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->claim($order['order_uuid'])->assertOk();

        $tablet = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "pos_tablet_orders" where "order_id" = ?'));
        $locked = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "pos_orders" where "pos_orders"."id" = ? and "uuid" = ?'));
        $this->assertNotFalse($tablet);
        $this->assertNotFalse($locked);
        $this->assertLessThan($locked, $tablet);
    }
}
