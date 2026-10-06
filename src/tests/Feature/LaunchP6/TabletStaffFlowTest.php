<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TabletOrderEvent;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A items 4–7 and 10 — staff and tablet orders (tester calls
 * 10–13 and 15): take / take over ("Taken by", audited), the ring, send to
 * the kitchen unpaid (admitted by the kitchen feed and print claim, listed
 * Unpaid, never auto-cancelled), cash first then the kitchen, pay (server
 * receipt number, points earned, stock and packaging by order type, the cash
 * in the paying till's drawer), cancel before / after sending, and the
 * old-build gating.
 */
final class TabletStaffFlowTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
        $this->p6EnableNumbering();
    }

    /** @return array<string, mixed> the staff list rows keyed by tablet order uuid */
    private function listed(bool $unpaidOnly = false): array
    {
        return collect($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders'.($unpaidOnly ? '?unpaid_only=1' : ''))
            ->assertOk()->json('data.orders'))->keyBy('tablet_order_uuid')->all();
    }

    public function test_the_first_take_wins_and_a_take_over_is_audited(): void
    {
        $uuid = $this->p6Submit()->assertCreated()->json('data.tablet_order_uuid');

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertOk()
            ->assertJsonPath('data.outcome', 'taken')->assertJsonPath('data.order.taken_by.name', 'Cashier 7');
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertOk()
            ->assertJsonPath('data.outcome', 'already_yours');
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_taken')->assertJsonPath('data.taken_by.name', 'Cashier 7')
            ->assertJsonPath('data.taken_by.staff_id', 7);
        // Sending is an action of the taker too.
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$uuid}/send-to-kitchen")->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_taken');

        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take", ['take_over' => true])->assertOk()
            ->assertJsonPath('data.outcome', 'taken_over')->assertJsonPath('data.order.taken_by.name', 'Supervisor 9')
            ->assertJsonPath('data.order.taken_by.device_id', (int) $this->handheld->id);
        $this->assertSame('Supervisor 9', $this->listed()[$uuid]['taken_by']['name']);

        $events = TabletOrderEvent::query()->orderBy('id')->get();
        $this->assertSame(['submitted', 'taken', 'taken_over'], $events->pluck('event_type')->all());
        $this->assertSame([null, 7, 9], $events->pluck('staff_id')->map(fn ($v) => $v === null ? null : (int) $v)->all());
        $this->assertSame(['previous_staff_id' => 7, 'previous_device_id' => (int) $this->till->id], $events[2]->payload);

        // A staff member's token is required; an unknown tablet order is 404.
        $this->p6As($this->till, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertForbidden()
            ->assertJsonPath('errors.0.code', 'staff_unverified');
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/tablet-orders/'.Str::uuid().'/take')->assertNotFound()
            ->assertJsonPath('errors.0.code', 'tablet_order_not_found');
    }

    public function test_the_ring_lasts_until_taken_and_old_builds_see_no_tablet_rows(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $key = 'tablet:'.$order['tablet_order_uuid'];

        $capable = $this->p6As($this->handheld, 'GET', '/api/v1/device/order-attention', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->assertOk()->json('data');
        $this->assertSame([$key], $capable['tablet_order_keys']);
        // An old build's snapshot is exactly as before.
        $this->assertSame(['version' => 1, 'quick_order_keys' => [], 'table_round_keys' => []],
            $this->p6As($this->handheld, 'GET', '/api/v1/device/order-attention')->assertOk()->json('data'));
        $this->assertSame([], $this->p6As($this->till, 'GET', '/api/v1/device/orders/active')->assertOk()->json('data.orders'));
        $this->assertSame([$order['order_uuid']], array_column($this->p6As($this->till, 'GET', '/api/v1/device/orders/active', [],
            ['X-Pos-Capabilities' => 'pay, tablet-orders'])->assertOk()->json('data.orders'), 'uuid'));

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/take")->assertOk();
        $this->assertSame([], $this->p6As($this->handheld, 'GET', '/api/v1/device/order-attention', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->json('data.tablet_order_keys'));
    }

    public function test_send_unpaid_the_kitchen_admits_it_it_stays_unpaid_and_is_never_auto_cancelled_then_pay_numbers_it(): void
    {
        $order = $this->p6Submit(['lines' => [$this->p6Line($this->coffee, 2)]])->assertCreated()->json('data');
        $uuid = $order['tablet_order_uuid'];

        $sent = $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/send-to-kitchen")->assertOk()->json('data');
        $this->assertSame(['sent', 'sent', 'Cashier 7', true, false], [$sent['outcome'], $sent['order']['state'],
            $sent['order']['sent_to_kitchen']['name'], $sent['order']['unpaid'], $sent['order']['paid']]);
        $this->assertSame('Cashier 7', $sent['order']['taken_by']['name']);
        $round = QrOrderRound::query()->sole();
        $this->assertSame([QrOrderRound::STATUS_ACCEPTED, 2000], [$round->status, (int) $round->total_baisas]);
        $this->assertNotNull($round->accepted_seq);
        $this->assertSame('replayed', $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/send-to-kitchen")
            ->assertOk()->json('data.outcome'));
        $this->assertSame(1, QrOrderRound::query()->count());

        // The kitchen: the feed (tablet-orders builds only) and the print claim.
        $feed = $this->p6As($this->till, 'GET', '/api/v1/device/qr/accepted-rounds', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->assertOk()->json('data.rounds');
        $this->assertSame([[(int) $round->id, 'quick', 'customer_tablet', $uuid, '1']], array_map(static fn (array $r): array => [$r['id'],
            $r['order_type'], $r['origin'], $r['tablet_order_uuid'], $r['order_number']], $feed));
        $this->assertSame(2, $feed[0]['priced_lines'][0]['qty']);
        $this->assertSame([], $this->p6As($this->till, 'GET', '/api/v1/device/qr/accepted-rounds')->assertOk()->json('data.rounds'));
        $this->p6As($this->handheld, 'POST', '/api/v1/device/kitchen/claim-print', ['ticket_key' => 'round:'.$round->id])
            ->assertCreated()->assertJsonPath('data.order_uuid', $order['order_uuid']);

        // Listed Unpaid (also for the shift-close warning) and never auto-cancelled.
        $this->assertTrue($this->listed(true)[$uuid]['unpaid']);
        $this->travel(3)->days();
        $this->artisan('qr:sweep-stale-charges')->assertSuccessful();
        $this->artisan('qr:prune-sessions')->assertSuccessful();
        $this->assertSame(Order::STATUS_HELD, $this->p6Order($order['order_uuid'])->status);
        $this->assertArrayHasKey($uuid, $this->listed(true));

        // Staff take the cash later: the server numbers the receipt.
        $this->p6PayCash($this->till, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.receipt_number', 'KLD-0001');
        $this->assertSame('KLD-0001', $this->p6Order($order['order_uuid'])->receipt_number);
        $this->assertArrayNotHasKey($uuid, $this->listed());
        $this->assertSame([], $this->listed(true));
    }

    public function test_cash_first_then_the_kitchen_with_points_earned_and_to_go_stock_and_packaging(): void
    {
        $rule = $this->p6Rule();
        // A to-go-only paper bag: the order's packaging, by its type.
        $bag = $this->p4Product('Paper bag', '0.000', ['stock_mode' => 'unit', 'is_internal' => true]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $bag, 'is_available' => true,
            'stock_qty' => '10.000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_order_packaging_lines')->insert(['company_id' => 100, 'order_type' => 'to_go', 'product_id' => $bag,
            'quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->p6Submit(['order_type' => 'to_go', 'phone' => '91234567'])->assertCreated()->json('data');
        $uuid = $order['tablet_order_uuid'];

        $this->p6PayCash($this->handheld, $order['order_uuid'], 2000, 9)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $paid = $this->p6Order($order['order_uuid']);
        $this->assertSame([Order::STATUS_PAID, 'KLD-0001', 'to_go'], [$paid->status, $paid->receipt_number, $paid->stock_order_type]);
        $this->assertSame([['type' => 'product', 'product_id' => $bag, 'qty' => 1]], $paid->packaging_snapshot_json['lines']);
        $this->assertSame(20, (int) DB::table('pos_loyalty_accounts')->where('loyalty_rule_id', $rule)
            ->where('customer_id', $paid->customer_id)->value('point_balance'));
        // Paid but not yet sent: still on the list, not Unpaid.
        $row = $this->listed()[$uuid];
        $this->assertSame(['pending', true, false, '9xxx4567'], [$row['state'], $row['paid'], $row['unpaid'], $row['phone_masked']]);
        $this->assertSame([], $this->listed(true));

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/send-to-kitchen")->assertOk()
            ->assertJsonPath('data.order.state', 'sent');
        $this->assertArrayNotHasKey($uuid, $this->listed());
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_closed');
    }

    public function test_cancel_before_sending_is_an_unpaid_void_and_after_sending_books_the_made_food_as_waste(): void
    {
        DB::table('pos_ingredients')->insert(['id' => 61, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Beans',
            'unit' => 'g', 'default_unit_cost' => '0.010000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_products')->where('id', $this->coffee)->update(['stock_mode' => 'ingredient']);
        DB::table('pos_product_recipes')->insert(['product_id' => $this->coffee, 'ingredient_id' => 61, 'quantity' => '10',
            'unit_at_set' => 'g', 'created_at' => now(), 'updated_at' => now()]);
        $made = (int) DB::table('pos_void_reasons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'code' => 'MADE',
            'name' => 'Made, not wanted', 'affects_inventory' => true, 'requires_manager' => false, 'created_at' => now(), 'updated_at' => now()]);
        $void = fn (string $orderUuid): array => $this->p6As($this->till, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'staff_id' => 7, 'void_reason_id' => $made, 'voided_at' => now()->toIso8601String()],
        ]]])->assertOk()->json('data.results.0');

        $notSent = $this->p6Submit()->assertCreated()->json('data');
        $this->assertSame('processed', $void($notSent['order_uuid'])['status']);
        $this->assertSame(0, WasteRecord::query()->count());

        $sent = $this->p6Submit()->assertCreated()->json('data');
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$sent['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $result = $void($sent['order_uuid']);
        $this->assertSame('processed', $result['status']);
        $this->assertSame(Order::STATUS_VOID, $this->p6Order($sent['order_uuid'])->status);
        $waste = WasteRecord::query()->sole();
        $this->assertSame([61, 20.0], [(int) $waste->ingredient_id, (float) $waste->quantity]);
        $this->assertStringContainsString('customer tablet', (string) $waste->notes);
        $this->assertSame([], $this->listed());
    }

    public function test_tablet_cash_lands_in_the_paying_tills_shift(): void
    {
        $shift = (string) Str::uuid();
        $this->p5Push('mdev_p6_till', [$this->p5Event('shift.open', ['uuid' => $shift, 'staff_id' => 7, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHour()->toIso8601String()], at: now()->subHour()->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $order = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->till, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $close = $this->p5Push('mdev_p6_till', [$this->p5Event('shift.close', ['shift_uuid' => $shift, 'closing_cash_baisas' => 7000,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 7, 'order_uuids' => [$order['order_uuid']], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$shift)->toString())])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $close['status']);
        $this->assertSame([7000, 0, 1, 2000], [$close['result']['expected_cash_baisas'], $close['result']['variance_baisas'],
            $close['result']['summary']['order_count'], $close['result']['summary']['grand_total_baisas']]);
    }
}
