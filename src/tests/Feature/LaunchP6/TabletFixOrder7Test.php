<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Actions\Tablet\TabletLoyalty;
use App\Models\Device;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 7 (LAUNCH-P6_A_FIX_ORDER_7.md) — F-21 (a table
 * bill the tablet opened can be checked out on any attended device of the
 * branch) and F-22 (the staff Row's `order_total_baisas`).
 */
final class TabletFixOrder7Test extends TestCase
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

    /**
     * The tablet opens the table with 2 coffees and adds 3 more; both rounds are sent.
     *
     * @return array{0: Table, 1: Order}
     */
    private function tabletBill(string $label): array
    {
        $table = $this->seatingTable($label);
        foreach ([2, 3] as $qty) {
            $round = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'lines' => [$this->p6Line($this->coffee, $qty)]])
                ->assertCreated()->json('data');
            $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$round['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        }
        $bill = $this->p6Order($round['order_uuid']);
        $this->assertSame(['customer_tablet', (int) $this->tablet->id, '5.000'], [$bill->source, (int) $bill->device_id, $bill->grand_total]);

        return [$table, $bill];
    }

    private function claim(Device $device, string $orderUuid): TestResponse
    {
        return $this->p6As($device, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $orderUuid]);
    }

    // ---- F-21 ----

    public function test_f21_a_till_and_a_handheld_check_out_and_pay_a_table_bill_the_tablet_opened(): void
    {
        foreach ([[$this->till, 'RV test'], [$this->handheld, 'RV test 2']] as [$device, $label]) {
            [, $bill] = $this->tabletBill($label);

            $this->claim($device, $bill->uuid)->assertOk()->assertJsonPath('data.charge_amount_baisas', 5000);
            $this->p6As($device, 'GET', "/api/v1/device/qr/orders/{$bill->uuid}/checkout")->assertOk()
                ->assertJsonPath('data.claim.charge_amount_baisas', 5000);
            $this->p6PayCash($device, $bill->uuid, 5000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

            $paid = $bill->fresh();
            $this->assertSame(Order::STATUS_PAID, $paid->status, $label);
            $this->assertMatchesRegularExpression('/^KLD-\d{4}$/', (string) $paid->receipt_number);
            $this->assertSame(TableSession::STATUS_CLOSED, TableSession::query()->where('order_id', $bill->id)->sole()->status);
        }
    }

    public function test_f21_a_bill_a_till_opened_still_needs_its_owner_on_another_device(): void
    {
        $table = $this->seatingTable('Table 9');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $this->till->id]);
        $id = (string) Str::uuid();
        $this->p6As($this->till, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(), 'staff_id' => 7,
                'lines' => [['product_id' => $this->cake, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $bill = Order::query()->sole();

        $this->claim($this->handheld, $bill->uuid)->assertStatus(409)->assertJsonPath('errors.0.code', 'staff_bill_owner_required');
        $this->claim($this->till, $bill->uuid)->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000);
    }

    public function test_f21_another_branch_or_merchant_cannot_reach_the_tablet_bill(): void
    {
        [, $bill] = $this->tabletBill('RV test');
        foreach ([$this->p6Device('mdev_p6_b11', 'fixed_pos', 100, 11), $this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20)] as $device) {
            $this->claim($device, $bill->uuid)->assertNotFound()->assertJsonPath('errors.0.code', 'order_not_found');
            $this->assertSame('failed', $this->p6PayCash($device, $bill->uuid, 5000)->json('data.results.0.status'));
        }
        // The tablet itself is not an attended device.
        $this->claim($this->tablet, $bill->uuid)->assertForbidden();
        $this->assertSame([Order::STATUS_OPEN, null], [$bill->fresh()->status, $bill->fresh()->charge_claimed_at]);
    }

    // ---- F-23 — equivalent Omani phone forms ----

    /** A customer saved as typed by an older flow (no canonical column). */
    private function storedAs(string $phone, int $company = 100): int
    {
        return (int) DB::table('pos_customers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company,
            'name' => 'Stored '.$phone, 'phone' => $phone, 'phone_canonical' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function lookup(string $phone): array
    {
        return $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => $phone])->assertOk()->json('data');
    }

    public function test_f23_a_customer_stored_in_any_omani_form_is_found_with_their_balance(): void
    {
        $rule = $this->p6Rule();
        foreach (['99990121', '96899990122', '0096899990123', '+96899990124'] as $index => $stored) {
            $this->p6Account($this->storedAs($stored), $rule, 600 + $index);
        }

        foreach (['9999 0121' => 600, '+968 9999 0122' => 601, '99990123' => 602, '0096899990124' => 603] as $typed => $balance) {
            $data = $this->lookup((string) $typed);
            $this->assertSame(['existing', $balance], [$data['customer'], $data['accounts'][0]['balance'] ?? null], (string) $typed);
        }
    }

    public function test_f23_among_duplicates_the_one_with_points_is_chosen_deterministically(): void
    {
        $rule = $this->p6Rule();
        // The device run: an E.164 duplicate without points (lower id) and the real one stored as 968….
        $this->p6Customer('+96899990121', 'Duplicate');
        $real = $this->storedAs('96899990121');
        $this->p6Account($real, $rule, 611);
        // Two canonical rows: the later one has the points.
        $this->p6Customer('+96899990131', 'First');
        $withPoints = $this->p6Customer('0096899990131', 'With points');
        $this->p6Account($withPoints, $rule, 50);
        // Neither has points: the most recent order wins, then the lowest id.
        $old = $this->storedAs('99990141');
        $recent = $this->storedAs('+96899990141');
        DB::table('pos_orders')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'customer_id' => $recent,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos', 'subtotal' => 1, 'grand_total' => 1,
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(611, $this->lookup('99990121')['accounts'][0]['balance']);
        $this->assertSame(50, $this->lookup('99990131')['accounts'][0]['balance']);
        $this->assertSame($recent, TabletLoyalty::customer(100, '+96899990141')?->id);
        DB::table('pos_orders')->delete();
        $this->assertSame($old, TabletLoyalty::customer(100, '+96899990141')?->id);
    }

    public function test_f23_submit_links_the_existing_customer_never_a_duplicate_and_leaves_stored_phones_as_they_are(): void
    {
        $rule = $this->p6Rule();
        $customer = $this->storedAs('99990601');
        $this->p6Account($customer, $rule, 500);
        $before = DB::table('pos_customers')->count();

        $points = $this->p6Submit(['phone' => '9999 0601', 'payment' => 'points', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 1]])
            ->assertCreated()->json('data');
        $plain = $this->p6Submit(['phone' => '+96899990601'])->assertCreated()->json('data');

        foreach ([$points, $plain] as $order) {
            $this->assertSame($customer, (int) $this->p6Order($order['order_uuid'])->customer_id);
            $this->assertSame($customer, (int) $this->p6Row($order['tablet_order_uuid'])->customer_id);
        }
        $this->assertSame($before, DB::table('pos_customers')->count());
        $this->assertSame(['99990601', null], [DB::table('pos_customers')->where('id', $customer)->value('phone'),
            DB::table('pos_customers')->where('id', $customer)->value('phone_canonical')]);
        // A number no form matches still creates one new customer.
        $new = $this->p6Submit(['phone' => '99990602'])->assertCreated()->json('data');
        $this->assertSame($before + 1, DB::table('pos_customers')->count());
        $this->assertSame('+96899990602', DB::table('pos_customers')->where('id', $this->p6Order($new['order_uuid'])->customer_id)->value('phone'));
    }

    public function test_f23_another_merchants_customer_with_the_same_number_is_never_matched(): void
    {
        $foreign = $this->storedAs('99990121', 200);
        $this->p6Account($foreign, $this->p6Rule(200), 9999, 200);

        $this->assertSame(['new', []], array_values(array_intersect_key($this->lookup('99990121'), array_flip(['customer', 'accounts']))));
        $order = $this->p6Submit(['phone' => '99990121'])->assertCreated()->json('data');
        $mine = (int) $this->p6Order($order['order_uuid'])->customer_id;
        $this->assertNotSame($foreign, $mine);
        $this->assertSame(100, (int) DB::table('pos_customers')->where('id', $mine)->value('company_id'));
        // Once this merchant has its own, the foreign one (with more points) is still never chosen.
        $this->assertSame($mine, TabletLoyalty::customer(100, '+96899990121')?->id);
    }

    // ---- F-22 ----

    /** @return array<string, mixed> */
    private function row(string $uuid): array
    {
        return collect($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->assertOk()->json('data.orders'))
            ->firstWhere('tablet_order_uuid', $uuid);
    }

    public function test_f22_each_row_shows_its_own_order_total_next_to_the_bill(): void
    {
        $table = $this->seatingTable('RV test');
        $first = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'lines' => [$this->p6Line($this->coffee, 2)]])
            ->assertCreated()->json('data');
        // Pending: the bill has nothing yet, the order is 2.000.
        $this->assertSame([2000, 0], [$this->row($first['tablet_order_uuid'])['order_total_baisas'], $this->row($first['tablet_order_uuid'])['grand_total_baisas']]);

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$first['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $this->assertSame([2000, 2000], [$this->row($first['tablet_order_uuid'])['order_total_baisas'], $this->row($first['tablet_order_uuid'])['grand_total_baisas']]);

        $second = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'lines' => [$this->p6Line($this->cake), $this->p6Line($this->coffee)]])
            ->assertCreated()->json('data');
        // Edited before sending: its own total follows.
        $this->p6Staff($this->till, 7, 'PUT', "/api/v1/device/tablet-orders/{$second['tablet_order_uuid']}/lines",
            ['client_request_id' => (string) Str::uuid(), 'lines' => [$this->p6Line($this->coffee, 3)]])->assertOk();
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$second['tablet_order_uuid']}/send-to-kitchen")->assertOk();

        $this->assertSame([2000, 5000], [$this->row($first['tablet_order_uuid'])['order_total_baisas'], $this->row($first['tablet_order_uuid'])['grand_total_baisas']]);
        $this->assertSame([3000, 5000], [$this->row($second['tablet_order_uuid'])['order_total_baisas'], $this->row($second['tablet_order_uuid'])['grand_total_baisas']]);

        // A Quick order: its own total is the order's.
        $quick = $this->p6Submit(['lines' => [$this->p6Line($this->cake)]])->assertCreated()->json('data');
        $this->assertSame([2000, 2000], [$this->row($quick['tablet_order_uuid'])['order_total_baisas'], $this->row($quick['tablet_order_uuid'])['grand_total_baisas']]);
    }
}
