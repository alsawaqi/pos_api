<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\StaffTableCheckoutAction;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A items 2, 5, 6 and 8 — Dine in from the tablet: a pending
 * round on the table's bill (a new bill opened by the tablet has source
 * customer_tablet; a second round joins an open bill without rewriting its
 * source — tester call 14), shown on the table board as "Tablet" to
 * tablet-orders builds only (tester call 15), sent to the kitchen by staff
 * confirming it (tester call 13), paid on the existing table path.
 */
final class TabletDineInTest extends TestCase
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

    /** @return array<string, mixed> the board row of a table */
    private function boardRow(int $tableId, bool $capable): array
    {
        return collect($this->p6As($this->till, 'GET', '/api/v1/device/tables/board', [],
            $capable ? ['X-Pos-Capabilities' => 'tablet-orders'] : [])->assertOk()->json('data.tables'))->firstWhere('table_id', $tableId);
    }

    public function test_a_tablet_opens_a_table_bill_staff_confirm_the_round_into_the_kitchen_and_it_is_paid_with_points_earned(): void
    {
        $rule = $this->p6Rule();
        $table = $this->seatingTable('Table 5');
        $data = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'phone' => '91234567',
            'lines' => [$this->p6Line($this->coffee), $this->p6Line($this->cake)]])->assertCreated()->json('data');

        $this->assertSame(['dine_in', null, ['uuid' => $table->uuid, 'name' => 'Table 5'], 12], [$data['order_type'], $data['order_number'],
            $data['table'], $data['ready_in_minutes']]);
        $bill = $this->p6Order($data['order_uuid']);
        $seating = TableSession::query()->sole();
        $this->assertSame(['customer_tablet', 'dine_in', Order::STATUS_OPEN, '0.000'], [$bill->source, $bill->order_type, $bill->status, $bill->grand_total]);
        $this->assertSame(['customer_tablet', (int) $this->tablet->id, (int) $bill->id], [$seating->origin, (int) $seating->opened_by_device_id,
            (int) $seating->order_id]);
        $this->assertNotNull($bill->customer_id);
        $round = QrOrderRound::query()->sole();
        $this->assertSame([QrOrderRound::STATUS_PENDING_CONFIRMATION, null, 3000], [$round->status, $round->qr_session_id, (int) $round->total_baisas]);
        $this->assertSame(['opened', 'round_pending', 'customer_order_arrived'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());

        // The board: the seating for everyone, the pending "Tablet" round only for tablet-orders builds.
        $old = $this->boardRow((int) $table->id, false);
        $this->assertSame([[], 0, 'customer_tablet'], [$old['seating']['pending_rounds'], $old['bill']['pending_rounds'], $old['bill']['source']]);
        $new = $this->boardRow((int) $table->id, true)['seating']['pending_rounds'];
        $this->assertSame([[(int) $round->id, 'customer_tablet', $data['tablet_order_uuid']]],
            array_map(static fn (array $r): array => [$r['round_id'], $r['origin'], $r['tablet_order_uuid']], $new));
        $this->assertSame([], $this->p6As($this->till, 'GET', '/api/v1/device/qr/accepted-rounds', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->json('data.rounds'));
        $this->assertSame(['tablet:'.$data['tablet_order_uuid']], $this->p6As($this->handheld, 'GET', '/api/v1/device/order-attention', [],
            ['X-Pos-Capabilities' => 'tablet-orders'])->json('data.tablet_order_keys'));

        // Send = confirm the round: it joins the bill and the kitchen gets it.
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$data['tablet_order_uuid']}/send-to-kitchen")->assertOk()
            ->assertJsonPath('data.order.state', 'sent')->assertJsonPath('data.order.round_status', 'accepted');
        $this->assertSame(['3.000', 'customer_tablet'], [$bill->fresh()->grand_total, $bill->fresh()->source]);
        $feed = $this->p6As($this->till, 'GET', '/api/v1/device/qr/accepted-rounds', [], ['X-Pos-Capabilities' => 'tablet-orders'])->json('data.rounds');
        $this->assertSame([[(int) $round->id, 'dine_in', 'customer_tablet', 'Table 5']], array_map(static fn (array $r): array => [$r['id'],
            $r['order_type'], $r['origin'], $r['table_label']], $feed));
        $this->assertSame([], $this->p6As($this->till, 'GET', '/api/v1/device/qr/accepted-rounds')->json('data.rounds'));
        $this->p6As($this->till, 'POST', '/api/v1/device/kitchen/claim-print', ['ticket_key' => 'round:'.$round->id])->assertCreated();
        $row = collect($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'))->sole();
        $this->assertSame(['sent', true, $seating->uuid], [$row['state'], $row['unpaid'], $row['table_session_uuid']]);

        // The existing table checkout path takes the tablet's bill; pay numbers it and earns points.
        $this->assertTrue(StaffTableCheckoutAction::shape($bill->fresh()));
        $this->p6PayCash($this->till, $bill->uuid, 3000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $paid = $bill->fresh();
        $this->assertSame([Order::STATUS_PAID, 'KLD-0001', 'dine_in'], [$paid->status, $paid->receipt_number, $paid->stock_order_type]);
        $this->assertSame(30, (int) DB::table('pos_loyalty_accounts')->where('loyalty_rule_id', $rule)->value('point_balance'));
        $this->assertSame([], $this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'));
    }

    public function test_a_second_round_joins_the_open_staff_bill_without_rewriting_its_source(): void
    {
        $table = $this->seatingTable('Table 7');
        // A staff bill: the till's own first round (1 coffee, 1.000).
        $staff = DB::transaction(fn (): array => app(AppendStaffRoundAction::class)->handle($this->till, [
            'seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => false, 'staff_id' => 7,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $this->coffee, 'qty' => 1]],
        ], now(), now()));
        $bill = $this->p6Order($staff['order_uuid']);
        $this->assertSame(['main_pos', '1.000'], [$bill->source, $bill->grand_total]);

        $first = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'phone' => '91234567'])->assertCreated()->json('data');
        $second = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'lines' => [$this->p6Line($this->cake)]])
            ->assertCreated()->json('data');

        $this->assertSame([$bill->uuid, $bill->uuid], [$first['order_uuid'], $second['order_uuid']]);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, TableSession::query()->count());
        $fresh = $bill->fresh();
        $this->assertSame(['main_pos', null, (int) $this->till->id], [$fresh->source, $fresh->qr_session_id, (int) $fresh->device_id]);
        $this->assertNotNull($fresh->customer_id, 'the typed phone links a bill that had no customer');
        $this->assertSame([1, 2, 3], QrOrderRound::query()->where('order_id', $bill->id)->orderBy('round_no')->pluck('round_no')->all());

        foreach ([$first, $second] as $data) {
            $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$data['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        }
        $this->assertSame(['5.000', 'main_pos'], [$bill->fresh()->grand_total, $bill->fresh()->source]);
        // The rounds themselves record the tablet.
        $this->assertSame(2, DB::table('pos_tablet_orders')->where('order_id', $bill->id)->whereNotNull('round_id')->count());
    }

    public function test_confirming_the_tablet_round_from_the_table_board_sends_it_and_stops_the_ring(): void
    {
        $table = $this->seatingTable('Table 3');
        $data = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');
        $round = QrOrderRound::query()->sole();
        $seating = TableSession::query()->sole();

        $this->p6As($this->till, 'POST', "/api/v1/device/tables/{$seating->uuid}/rounds/{$round->id}/confirm")->assertOk()
            ->assertJsonPath('data.outcome', 'accepted');

        $row = $this->p6Row($data['tablet_order_uuid']);
        $this->assertNotNull($row->sent_to_kitchen_at);
        $this->assertSame([(int) $this->till->id, null], [(int) $row->sent_by_device_id, $row->sent_by_staff_id]);
        $this->assertSame([], $this->p6As($this->handheld, 'GET', '/api/v1/device/order-attention', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->json('data.tablet_order_keys'));
        $listed = collect($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'))->sole();
        $this->assertSame(['sent', 'accepted'], [$listed['state'], $listed['round_status']]);
        $this->assertSame('replayed', $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$data['tablet_order_uuid']}/send-to-kitchen")
            ->json('data.outcome'));
        // Rejecting a pending tablet round from the board drops it from the list.
        $second = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');
        $pending = QrOrderRound::query()->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)->sole();
        $this->p6As($this->till, 'POST', "/api/v1/device/tables/{$seating->uuid}/rounds/{$pending->id}/reject")->assertOk();
        $this->assertNotContains($second['tablet_order_uuid'],
            array_column($this->p6Staff($this->till, 7, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'), 'tablet_order_uuid'));
    }

    public function test_a_table_whose_bill_is_being_paid_or_another_branchs_table_is_refused(): void
    {
        $table = $this->seatingTable('Table 9');
        $seating = $this->seatingRow($table, ['status' => TableSession::STATUS_BILLING, 'billing_at' => now()]);
        $this->seatingOrder($seating, ['source' => 'main_pos', 'status' => Order::STATUS_AWAITING_PAYMENT]);

        $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'table_bill_not_open');
        $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $this->seatingTable('Far', 20, 200)->uuid])->assertNotFound()
            ->assertJsonPath('errors.0.code', 'table_not_found');
        $this->p6Submit(['order_type' => 'dine_in'])->assertStatus(422);
        $this->p6Submit(['order_type' => 'quick', 'table_uuid' => $table->uuid])->assertStatus(422);
        $this->assertSame(0, QrOrderRound::query()->count());
    }
}
