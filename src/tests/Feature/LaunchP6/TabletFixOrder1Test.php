<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Actions\Tables\AdjustTableBillAction;
use App\Actions\Tables\CancelStaffLineAction;
use App\Broadcasting\BranchChannel;
use App\Broadcasting\CompanyChannel;
use App\Broadcasting\DeviceChannel;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\StockMovement;
use App\Models\TableSession;
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
 * LAUNCH-P6 Part A fix order 1 (LAUNCH-P6_A_FIX_ORDER_1.md) — F-1 to F-4 and
 * F-6 to F-8 (F-5's limiter is in TabletFixOrder1LimiterTest, throttling on).
 */
final class TabletFixOrder1Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    // ---- F-1 — QR / tablet cash on a shared shift ----

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

    private function qrQuickOrder(): Order
    {
        return Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'source' => Order::SOURCE_QR_WEB, 'order_type' => 'quick', 'status' => Order::STATUS_HELD,
            'subtotal' => '3.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '3.000', 'opened_at' => now()->subMinutes(5), 'temp_reference' => 'T-1006-099']);
    }

    public function test_f1_tablet_and_qr_cash_taken_on_a_device_without_a_shift_lands_in_the_payers_shared_shift(): void
    {
        $shift = $this->openShift('mdev_p6_till', 7);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $qr = $this->qrQuickOrder();
        $this->p6PayCash($this->handheld, $qr->uuid, 3000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame([7, 7], DB::table('pos_payments')->orderBy('id')->pluck('staff_id')->map(fn ($v) => (int) $v)->all());

        $z = $this->closeShift('mdev_p6_till', $shift, 7, 10000);

        $this->assertSame([10000, 0, 2, 5000], [$z['expected_cash_baisas'], $z['variance_baisas'], $z['summary']['order_count'],
            $z['summary']['grand_total_baisas']]);
    }

    public function test_f1_the_other_way_round_a_shift_opened_on_the_handheld_takes_cash_paid_on_the_till(): void
    {
        $shift = $this->openShift('mdev_p6_handheld', 9);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->till, $tablet['order_uuid'], 2000, 9)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $z = $this->closeShift('mdev_p6_handheld', $shift, 9, 7000);

        $this->assertSame([7000, 0, 1], [$z['expected_cash_baisas'], $z['variance_baisas'], $z['summary']['order_count']]);
    }

    /** Fix order 2 (F-9): the payer's own shared shift first, so the cash follows staff 7 to T, never twice. */
    public function test_f1_a_payer_with_a_shared_shift_elsewhere_keeps_the_cash_so_nothing_counts_twice(): void
    {
        $tillShift = $this->openShift('mdev_p6_till', 7);
        $handheldShift = $this->openShift('mdev_p6_handheld', 9);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $qr = $this->qrQuickOrder();
        $this->p6PayCash($this->handheld, $qr->uuid, 3000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $till = $this->closeShift('mdev_p6_till', $tillShift, 7, 10000);
        $handheld = $this->closeShift('mdev_p6_handheld', $handheldShift, 9, 5000);

        $this->assertSame([10000, 0, 2], [$till['expected_cash_baisas'], $till['variance_baisas'], $till['summary']['order_count']]);
        $this->assertSame([5000, 0, 0], [$handheld['expected_cash_baisas'], $handheld['variance_baisas'], $handheld['summary']['order_count']]);
    }

    // ---- F-2 — one points slot per bill; a cleared slot supersedes the approval ----

    /** @return array{0: string, 1: array<string, mixed>} the seating uuid and the tablet answer (one round, 2 coffees, 1 block) */
    private function dineInWithApprovedPoints(int $customerId, string $phone): array
    {
        $table = $this->seatingTable('Table '.Str::random(3));
        $order = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid, 'phone' => $phone, 'payment' => 'points',
            'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])->assertCreated()->json('data');
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $this->approve($order['tablet_order_uuid'])->assertOk()->assertJsonPath('data.order.redeem.status', 'approved');

        return [(string) TableSession::query()->where('table_id', $table->id)->value('uuid'), $order];
    }

    private int $rule;

    private function approve(string $uuid): TestResponse
    {
        $ref = (string) Str::uuid();

        return $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$uuid}/redeem/approve",
            ['client_request_id' => $ref, 'auth_v' => 1, 'authorization' => $this->p5Position('loyalty.redeem', 8, $ref)]);
    }

    private function points(): int
    {
        $this->rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $this->p6Account($customer, $this->rule, 5000);

        return $customer;
    }

    /** @return array<string, mixed> */
    private function staffRow(string $uuid): array
    {
        return collect($this->p6Staff($this->till, 8, 'GET', '/api/v1/device/tablet-orders')->json('data.orders'))
            ->firstWhere('tablet_order_uuid', $uuid);
    }

    private function adjust(string $seatingUuid, array $adjustment): void
    {
        $seating = TableSession::query()->where('uuid', $seatingUuid)->firstOrFail();
        DB::transaction(fn () => app(AdjustTableBillAction::class)->handle($this->till, ['seating_key' => (string) $seating->client_request_id,
            'table_id' => (int) $seating->table_id, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => $adjustment], now(), now(), $seatingUuid));
    }

    public function test_f2_a_second_approval_on_the_same_bill_is_refused(): void
    {
        $this->points();
        [$seating, $first] = $this->dineInWithApprovedPoints(0, '91234567');
        $table = TableSession::query()->where('uuid', $seating)->value('table_id');
        $second = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => DB::table('pos_tables')->where('id', $table)->value('uuid'),
            'phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])->assertCreated()->json('data');
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$second['tablet_order_uuid']}/send-to-kitchen")->assertOk();

        $this->approve($second['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'bill_points_already_used')
            ->assertJsonPath('errors.0.message', 'Points are already used on this bill.');

        $this->assertSame(TabletOrder::REDEEM_REQUESTED, $this->p6Row($second['tablet_order_uuid'])->redeem_status);
        $this->assertSame(TabletOrder::REDEEM_APPROVED, $this->p6Row($first['tablet_order_uuid'])->redeem_status);
        $this->assertSame(500, $this->staffRow($first['tablet_order_uuid'])['redeem']['amount_baisas']);
    }

    public function test_f2_a_staff_clear_a_customer_change_or_a_line_cancel_clamp_supersede_the_approval(): void
    {
        $this->points();
        $other = $this->p6Customer('+96899999999', 'Other');

        [$a, $cleared] = $this->dineInWithApprovedPoints(0, '91234567');
        $this->adjust($a, ['kind' => 'loyalty', 'mode' => 'clear']);
        [$b, $changed] = $this->dineInWithApprovedPoints(0, '91234567');
        $this->adjust($b, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $other]);
        [$c, $clamped] = $this->dineInWithApprovedPoints(0, '91234567');
        $seating = TableSession::query()->where('uuid', $c)->firstOrFail();
        // The handheld is an old build here (it never sent auth_v): the cancel takes its authorized_by text.
        DB::transaction(fn () => app(CancelStaffLineAction::class)->handle($this->handheld, ['seating_key' => (string) $seating->client_request_id,
            'table_id' => (int) $seating->table_id, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'product_id' => $this->coffee, 'addon_ids' => [], 'qty' => 2, 'prepared' => false, 'cancelled_at' => now()->toIso8601String(),
            'reason' => 'customer left', 'authorized_by' => 'Manager 8', 'staff_id' => 8], now(), now(), $c));

        foreach ([$cleared, $changed, $clamped] as $order) {
            $row = $this->p6Row($order['tablet_order_uuid']);
            $this->assertSame(TabletOrder::REDEEM_SUPERSEDED, $row->redeem_status, $order['tablet_order_uuid']);
            $event = TabletOrderEvent::query()->where('tablet_order_id', $row->id)->where('event_type', 'redeem_superseded')->sole();
            $this->assertSame((int) $row->redeem_discount_row_id, $event->payload['discount_row_id']);
            $redeem = $this->staffRow($order['tablet_order_uuid'])['redeem'] ?? null;
            if ($redeem !== null) {
                $this->assertSame(['superseded', 0, 500], [$redeem['status'], $redeem['amount_baisas'], $redeem['approved_amount_baisas']]);
            }
        }
    }

    public function test_f2_a_dine_in_approval_counts_once_toward_the_customers_day(): void
    {
        $this->points();
        $this->dineInWithApprovedPoints(0, '91234567');
        for ($i = 0; $i < 2; $i++) {
            $quick = $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])
                ->assertCreated()->json('data');
            $this->approve($quick['tablet_order_uuid'])->assertOk();
        }
        $fourth = $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $this->rule, 'blocks' => 1]])
            ->assertCreated()->json('data');
        $this->approve($fourth['tablet_order_uuid'])->assertStatus(409)->assertJsonPath('errors.0.code', 'loyalty_customer_limit');
    }

    // ---- F-3 — a device can neither replace nor fake a tablet order ----

    /** @return array<string, mixed> */
    private function deviceOrder(string $uuid, string $source = 'main_pos'): array
    {
        return ['uuid' => $uuid, 'order_type' => 'quick', 'source' => $source, 'staff_id' => 7, 'opened_at' => now()->toIso8601String(),
            'subtotal_baisas' => 9000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 9000,
            'lines' => [['product_id' => $this->cake, 'qty' => 1, 'unit_price_baisas' => 9000, 'line_total_baisas' => 9000]]];
    }

    private function push(string $type, array $order): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken('mdev_p6_till')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type, 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => $order],
        ]]])->assertOk()->json('data.results.0');
    }

    public function test_f3_a_resent_create_or_hold_never_replaces_a_tablet_order_and_a_device_cannot_label_one(): void
    {
        $rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $this->p6Account($customer, $rule, 500);
        $tablet = $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 1]])
            ->assertCreated()->json('data');
        $before = [$this->p6Order($tablet['order_uuid'])->getAttributes(), OrderItem::query()->orderBy('id')->get()->toArray(),
            $this->p6Row($tablet['tablet_order_uuid'])->getAttributes()];

        foreach (['order.create', 'order.hold'] as $type) {
            $result = $this->push($type, $this->deviceOrder($tablet['order_uuid']));
            $this->assertSame('failed', $result['status'], $type);
            $this->assertStringContainsString('tablet_order_replacement_forbidden', (string) $result['result']['error'], $type);
        }
        $this->assertSame($before, [$this->p6Order($tablet['order_uuid'])->getAttributes(), OrderItem::query()->orderBy('id')->get()->toArray(),
            $this->p6Row($tablet['tablet_order_uuid'])->getAttributes()]);

        $labelled = (string) Str::uuid();
        $this->assertSame('failed', $this->push('order.create', $this->deviceOrder($labelled, 'customer_tablet'))['status']);
        $this->assertNull(Order::query()->where('uuid', $labelled)->first());
    }

    public function test_f3_an_order_merely_labelled_customer_tablet_is_never_treated_as_one(): void
    {
        $this->p6EnableNumbering();
        $rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $labelled = Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->till->id, 'staff_id' => 7, 'customer_id' => $customer, 'source' => 'customer_tablet', 'order_type' => 'quick',
            'status' => Order::STATUS_HELD, 'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000',
            'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now()->subMinutes(5)]);
        $shift = $this->openShift('mdev_p6_till', 7);

        $this->p6PayCash($this->till, $labelled->uuid, 1000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertNull($labelled->fresh()->receipt_number);
        $this->assertSame(0, (int) DB::table('pos_loyalty_accounts')->where('loyalty_rule_id', $rule)->where('customer_id', $customer)->sum('point_balance'));
        // Its cash is the till's by the legacy rule (its own device and staff).
        $this->assertSame(6000, $this->closeShift('mdev_p6_till', $shift, 7, 6000)['expected_cash_baisas']);
    }

    // ---- F-4 — no live channels for a tablet ----

    public function test_f4_a_tablet_token_is_refused_on_both_broadcasting_auth_routes_and_by_every_channel(): void
    {
        foreach (['/broadcasting/auth', '/api/v1/broadcasting/auth'] as $uri) {
            foreach (['private-branch.10', 'private-company.100', 'private-device.'.$this->tablet->id] as $channel) {
                $this->p6As($this->tablet, 'POST', $uri, ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertForbidden();
            }
        }
        $this->p6As($this->till, 'POST', '/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-branch.10'])->assertOk();
        $this->assertSame([false, false, false], [(new BranchChannel)->join($this->tablet, 10), (new CompanyChannel)->join($this->tablet, 100),
            (new DeviceChannel)->join($this->tablet, (int) $this->tablet->id)]);
        $this->assertSame([true, true, true], [(new BranchChannel)->join($this->till, 10), (new CompanyChannel)->join($this->till, 100),
            (new DeviceChannel)->join($this->till, (int) $this->till->id)]);
    }

    // ---- F-6 / F-7 — the table screens and tablet rounds ----

    /** @return array{0: TableSession, 1: QrOrderRound, 2: array<string, mixed>} */
    private function pendingTabletRound(): array
    {
        $table = $this->seatingTable('Table 4');
        $order = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');

        return [TableSession::query()->sole(), QrOrderRound::query()->sole(), $order];
    }

    public function test_f6_old_builds_cannot_review_a_tablet_round_and_new_builds_record_the_staff_under_the_taker_rule(): void
    {
        [$seating, $round, $order] = $this->pendingTabletRound();
        $confirm = "/api/v1/device/tables/{$seating->uuid}/rounds/{$round->id}/confirm";
        $reject = "/api/v1/device/tables/{$seating->uuid}/rounds/{$round->id}/reject";

        foreach ([$confirm, $reject] as $uri) {
            $this->p6As($this->till, 'POST', $uri)->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_round_needs_update')
                ->assertJsonPath('errors.0.message', 'Update the app to handle tablet orders.');
            $this->p6As($this->till, 'POST', $uri, [], ['X-Pos-Capabilities' => 'tablet-orders'])->assertForbidden()
                ->assertJsonPath('errors.0.code', 'staff_unverified');
        }
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/take")->assertOk();
        $this->p6Staff($this->till, 7, 'POST', $confirm)->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_taken');
        $this->assertSame(QrOrderRound::STATUS_PENDING_CONFIRMATION, $round->fresh()->status);

        // Detail: "tablet" for a tablet-orders build, today's shape for an old one.
        $old = collect($this->p6As($this->till, 'GET', "/api/v1/device/tables/{$seating->table_id}/detail")->assertOk()->json('data.rounds'))->sole();
        $new = collect($this->p6As($this->till, 'GET', "/api/v1/device/tables/{$seating->table_id}/detail", [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->assertOk()->json('data.rounds'))->sole();
        $this->assertSame(['staff', false], [$old['entered_by'], array_key_exists('tablet_order_uuid', $old)]);
        $this->assertSame(['tablet', $order['tablet_order_uuid']], [$new['entered_by'], $new['tablet_order_uuid']]);

        $this->p6Staff($this->handheld, 9, 'POST', $confirm)->assertOk()->assertJsonPath('data.outcome', 'accepted');
        $row = $this->p6Row($order['tablet_order_uuid']);
        $this->assertSame([9, (int) $this->handheld->id], [(int) $row->sent_by_staff_id, (int) $row->sent_by_device_id]);

        // A rejection from the board is recorded with its staff member.
        $second = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => DB::table('pos_tables')->where('id', $seating->table_id)->value('uuid')])
            ->assertCreated()->json('data');
        $pending = QrOrderRound::query()->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)->sole();
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tables/{$seating->uuid}/rounds/{$pending->id}/reject")->assertOk()
            ->assertJsonPath('data.outcome', 'rejected');
        $event = TabletOrderEvent::query()->where('event_type', 'round_rejected')->sole();
        $this->assertSame([7, (int) $this->p6Row($second['tablet_order_uuid'])->id], [(int) $event->staff_id, (int) $event->tablet_order_id]);
    }

    public function test_f7_the_board_path_locks_the_tablet_row_before_any_table_lock(): void
    {
        [$seating, $round] = $this->pendingTabletRound();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tables/{$seating->uuid}/rounds/{$round->id}/confirm")->assertOk();

        $firstTable = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "pos_tables"'));
        $tabletReads = collect($queries)->keys()->filter(fn (int $i): bool => str_starts_with($queries[$i], 'select')
            && str_contains($queries[$i], 'from "pos_tablet_orders"'))->values();
        $this->assertNotFalse($firstTable);
        $this->assertNotEmpty($tabletReads);
        $this->assertTrue($tabletReads->every(fn (int $i): bool => $i < $firstTable), 'no tablet row is read (locked) after the table graph');
        $this->assertNotNull($this->p6Row(TabletOrder::query()->value('uuid'))->sent_to_kitchen_at);
    }

    // ---- F-8 — staff edit a tablet order before it is sent ----

    private function edit(?Device $device, int $staffId, string $uuid, array $lines, ?string $ref = null): TestResponse
    {
        return $this->p6Staff($device ?? $this->till, $staffId, 'PUT', "/api/v1/device/tablet-orders/{$uuid}/lines",
            ['client_request_id' => $ref ?? (string) Str::uuid(), 'lines' => $lines]);
    }

    public function test_f8_staff_edit_a_quick_order_and_pay_takes_the_new_lines(): void
    {
        DB::table('pos_ingredients')->insert(['id' => 61, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Beans',
            'unit' => 'g', 'default_unit_cost' => '0.010000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_products')->where('id', $this->coffee)->update(['stock_mode' => 'ingredient']);
        DB::table('pos_product_recipes')->insert(['product_id' => $this->coffee, 'ingredient_id' => 61, 'quantity' => '10',
            'unit_at_set' => 'g', 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->p6Submit(['lines' => [$this->p6Line($this->coffee, 2)]])->assertCreated()->json('data');
        $water = $this->p4Product('Water', '0.300', ['cooking_minutes' => 20]);
        $ref = (string) Str::uuid();

        $edited = $this->edit(null, 7, $order['tablet_order_uuid'], [$this->p6Line($this->coffee, 3), $this->p6Line($water)], $ref)
            ->assertOk()->json('data');

        $this->assertSame(['edited', 3300, 20, 'Cashier 7'], [$edited['outcome'], $edited['order']['grand_total_baisas'],
            $edited['order']['ready_in_minutes'], $edited['order']['taken_by']['name']]);
        $this->assertSame([3, 1], array_column($edited['order']['lines'], 'qty'));
        $held = $this->p6Order($order['order_uuid']);
        $this->assertSame(['3.300', 3300], [$held->grand_total, $this->p6Row($order['tablet_order_uuid'])->total_baisas]);
        $this->assertSame([$this->coffee => 3.0, $water => 1.0], OrderItem::query()->where('order_id', $held->id)->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)->all());
        $event = TabletOrderEvent::query()->where('event_type', 'edited')->sole();
        $this->assertSame([7, [2], [3, 1]], [(int) $event->staff_id, array_column($event->payload['before'], 'qty'), array_column($event->payload['after'], 'qty')]);
        // A repeat is a replay, changing nothing.
        $this->edit(null, 7, $order['tablet_order_uuid'], [$this->p6Line($this->coffee)], $ref)->assertOk()->assertJsonPath('data.outcome', 'replayed');
        $this->assertSame('3.300', $held->fresh()->grand_total);

        // Pay: stock follows the new lines (3 × 10 g of beans).
        $this->p6PayCash($this->till, $order['order_uuid'], 3300)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(-30.0, (float) StockMovement::query()->where('ingredient_id', 61)->sum('quantity'));
        // Refused after pay.
        $this->edit(null, 7, $order['tablet_order_uuid'], [$this->p6Line($this->coffee)])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_paid');
    }

    public function test_f8_staff_edit_a_pending_dine_in_round_and_the_confirmed_round_has_the_new_lines(): void
    {
        [$seating, $round, $order] = $this->pendingTabletRound();

        $this->edit($this->handheld, 9, $order['tablet_order_uuid'], [$this->p6Line($this->cake, 2)])->assertOk()
            ->assertJsonPath('data.order.total_baisas', 4000);
        // The bill itself waits for the send.
        $this->assertSame([4000, '0.000'], [(int) $round->fresh()->total_baisas, $this->p6Order($order['order_uuid'])->grand_total]);
        $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/send-to-kitchen")->assertOk();

        $bill = $this->p6Order($order['order_uuid']);
        $this->assertSame('4.000', $bill->grand_total);
        $this->assertSame([$this->cake => 2.0], OrderItem::query()->where('order_id', $bill->id)->pluck('qty', 'product_id')->map(fn ($q) => (float) $q)->all());
        $this->edit($this->handheld, 9, $order['tablet_order_uuid'], [$this->p6Line($this->coffee)])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_order_sent');
    }

    public function test_f8_edit_refusals_taker_points_sold_out_empty_sent_and_tenancy(): void
    {
        $rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $this->p6Account($customer, $rule, 500);
        $order = $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 2]])
            ->assertCreated()->json('data');
        $uuid = $order['tablet_order_uuid'];
        $ref = (string) Str::uuid();
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$uuid}/redeem/approve",
            ['client_request_id' => $ref, 'auth_v' => 1, 'authorization' => $this->p5Position('loyalty.redeem', 8, $ref)])->assertOk();

        // Taken by the manager: a cashier cannot edit without taking over.
        $this->edit(null, 7, $uuid, [$this->p6Line($this->coffee, 3)])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_taken');
        // 1.000 of points on 1.000 of coffee would leave nothing to pay.
        $this->edit(null, 8, $uuid, [$this->p6Line($this->coffee)])->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_exceeds_order');
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->cake,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->edit(null, 8, $uuid, [$this->p6Line($this->coffee), $this->p6Line($this->cake)])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'tablet_lines_unavailable')->assertJsonPath('data.lines.0.product_id', $this->cake);
        $this->edit(null, 8, $uuid, [])->assertStatus(422);
        $this->edit(null, 8, $uuid, [['notes' => 'extra hot'] + $this->p6Line($this->coffee, 3)])->assertStatus(422);
        $this->edit(null, 8, $uuid, [$this->p6Line($this->coffee, 3) + ['unit_price_baisas' => 1]])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'client_priced_payload_rejected');
        $this->assertSame('1.000', $this->p6Order($order['order_uuid'])->grand_total);
        // Within the points: 3 coffees, 2.000 left to pay.
        $this->edit(null, 8, $uuid, [$this->p6Line($this->coffee, 3)])->assertOk()->assertJsonPath('data.order.grand_total_baisas', 2000);

        // Another merchant's or branch's device never reaches it.
        foreach ([[$this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20), 20, 200, 20], [$this->p6Device('mdev_p6_b11', 'fixed_pos', 100, 11), 11, 100, 11]] as [$device, $staff, $company, $branch]) {
            $this->p5Staff($staff, 'manager', (string) (100000 + $staff), overrides: ['company_id' => $company, 'branch_id' => $branch]);
            $this->edit($device, $staff, $uuid, [$this->p6Line($this->coffee)])->assertNotFound();
        }

        // Sent: no more edits.
        $this->p6Staff($this->till, 8, 'POST', "/api/v1/device/tablet-orders/{$uuid}/send-to-kitchen")->assertOk();
        $this->edit(null, 8, $uuid, [$this->p6Line($this->coffee, 4)])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_sent');
    }
}
