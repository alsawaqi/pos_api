<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\CompReason;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\TableSessionEvent;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableLoyaltyTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-22 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7, 8]);
    }

    private function fixture(): array
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seat, ['subtotal' => '5.000', 'discount_total' => '0.550', 'tax_total' => '0.223', 'grand_total' => '4.673']);
        $lines = [];
        foreach ([[3, 1000, 300], [1, 2000, 0]] as $i => [$qty, $unit, $discount]) {
            $product = $this->seatingProduct();
            $item = OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id,
                'product_name_snapshot' => 'Frozen '.$i, 'qty' => $qty, 'unit_price_snapshot' => $unit / 1000,
                'line_total' => $qty * $unit / 1000, 'line_discount' => $discount / 1000, 'status' => 'open']);
            $lines[] = ['line_index' => $i, 'product_id' => (int) $product->id, 'product_name' => 'Frozen '.$i,
                'order_item_id' => (int) $item->id, 'qty' => $qty, 'unit_price_baisas' => $unit,
                'line_total_baisas' => $qty * $unit, 'line_discount_baisas' => $discount, 'addons' => [], 'notes' => null];
        }
        $round = $this->seatingRound($seat, $order, ['priced_lines' => $lines, 'subtotal_baisas' => 5000, 'tax_baisas' => 223, 'total_baisas' => 4673]);
        foreach ([[300, $lines[0]['order_item_id']], [250, null]] as [$amount, $itemId]) {
            DB::table('pos_order_discounts')->insert(['company_id' => 100, 'branch_id' => 10, 'order_id' => $order->id,
                'order_item_id' => $itemId, 'name_snapshot' => 'Frozen rule', 'amount_type_snapshot' => 'percent', 'amount' => $amount / 1000]);
        }
        $reason = CompReason::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'code' => 'T65', 'name' => 'Service recovery', 'is_active' => true]);

        return [$device, $seat, $order, $round, $reason];
    }

    private function postAs($device, string $path, array $data)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->device_token)->postJson('/api/v1/device/'.$path, $data);
    }

    private function intent($seat, array $adjustment): array
    {
        return ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'adjustment' => $adjustment];
    }

    private function adjust($device, $seat, array $adjustment)
    {
        return $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $this->intent($seat, $adjustment));
    }

    private function money($order): array
    {
        return array_map(fn ($key): string => Money::toOmr(Money::toBaisas($order->refresh()->$key)), ['subtotal', 'discount_total', 'comp_total', 'tax_total', 'grand_total']);
    }

    private function loyalty($order, string $kind = 'spend_based', int $company = 100): array
    {
        $customer = Customer::query()->create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'T12 synthetic', 'phone' => (string) (90000000 + Customer::count())]);
        $rule = LoyaltyRule::query()->create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'T12 reward', 'type' => $kind, 'status' => 'active',
            'config_json' => ['redemption_points' => 100, 'redemption_value' => '0.500', 'points_per_omr' => 10,
                'stamps_required' => 5, 'reward_type' => 'fixed', 'reward_value' => '0.750', 'min_order_value' => '0.001']]);
        $account = LoyaltyAccount::query()->create(['uuid' => Str::uuid(), 'company_id' => $company, 'customer_id' => $customer->id,
            'loyalty_rule_id' => $rule->id, 'point_balance' => 2000, 'stamp_count' => 100]);
        $order->update(['customer_id' => $customer->id]);

        return [$rule, $account, $customer];
    }

    private function redeem($rule, int $blocks = 1, int $staff = 7): array
    {
        return ['kind' => 'loyalty', 'mode' => 'redeem', 'rule_id' => $rule->id, 'blocks' => $blocks,
            'authorized_by' => 'T12 manager', 'approved_by_staff_id' => $staff];
    }

    private function pay($device, $order, ?string $id = null)
    {
        return $this->postAs($device, 'sync/push', ['events' => [[
            'client_event_id' => $id ?? (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(),
                'loyalty_redeem' => ['rule_id' => 999999, 'points' => 999999, 'stamps' => 99],
                'payments' => [['method' => 'cash', 'amount_baisas' => Money::toBaisas($order->fresh()->grand_total)]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    public static function goldenRedeems(): array
    {
        // S=5000, round discount=550, net=4450, T=223. Re-tax the remaining net, half-up.
        return [
            'points two blocks' => ['spend_based', 2, 1000, 200, 0, ['5.000', '1.550', '0.000', '0.173', '3.623']],
            'stamp two blocks' => ['visit_based', 2, 1500, 0, 10, ['5.000', '2.050', '0.000', '0.148', '3.098']],
        ];
    }

    #[DataProvider('goldenRedeems')]
    public function test_redeem_golden_real_route_frozen_round_and_proof(string $kind, int $blocks, int $value, int $points, int $stamps, array $money): void
    {
        [$d, $s, $o, $round] = $this->fixture();
        [$rule, $account] = $this->loyalty($o, $kind);
        $before = $round->refresh()->getRawOriginal();
        $this->adjust($d, $s, $this->redeem($rule, $blocks))->assertOk();
        $this->assertSame($money, $this->money($o));
        $this->assertSame($before, $round->fresh()->getRawOriginal());
        $this->assertSame(2000, $account->fresh()->point_balance);
        $this->assertSame(100, $account->fresh()->stamp_count);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
        $proof = TableSessionEvent::where('event_type', 'adjusted')->sole()->payload;
        foreach (['rule_id' => $rule->id, 'blocks' => $blocks, 'points' => $points, 'stamps' => $stamps, 'amount_baisas' => $value] as $k => $v) {
            $this->assertSame($v, $proof[$k]);
        }
        $row = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_loyalty_redeem')->sole();
        $this->assertNull($row->discount_id);
        $this->assertSame('T12 reward', $row->name_snapshot);
        $this->assertSame(($points ? 'points × '.$points : 'stamps × '.$stamps).' — rule '.$rule->id, $row->reason);
        $this->withToken($d->device_token)->getJson('/api/v1/device/tables/'.$s->table_id.'/detail')->assertOk()
            ->assertJsonPath('data.bill.adjustment_state.loyalty.points', $points)
            ->assertJsonPath('data.bill.loyalty_discount_baisas', $value);
        fwrite(STDOUT, "\nT12_GOLDEN=".json_encode(['kind' => $kind, 'value' => $value, 'points' => $points, 'stamps' => $stamps, 'measured' => $this->money($o)])."\n");
    }

    public function test_replace_clear_and_both_transports_replay_append_only(): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule] = $this->loyalty($o);
        $payload = $this->intent($s, $this->redeem($rule));
        $this->postAs($d, 'tables/'.$s->uuid.'/adjust', $payload)->assertOk();
        $original = DB::table('pos_order_discounts')->orderByDesc('id')->first();
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.adjust', 'client_timestamp' => now()->toIso8601String(), 'payload' => $payload];
        $this->postAs($d, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.result.outcome', 'replayed');
        $event['client_event_id'] = (string) Str::uuid();
        $event['payload'] = $this->intent($s, $this->redeem($rule, 2));
        $this->postAs($d, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.result.outcome', 'adjusted');
        $this->assertEquals([.5, -.5, 1.0], DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->orderBy('id')->pluck('amount')->all());
        $this->adjust($d, $s, ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        $this->assertEquals([.5, -.5, 1.0, -1.0], DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->orderBy('id')->pluck('amount')->all());
        $this->assertEquals($original, DB::table('pos_order_discounts')->where('id', $original->id)->sole());
        $this->assertSame(['5.000', '0.550', '0.000', '0.223', '4.673'], $this->money($o));
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public static function refused(): array
    {
        return array_map(fn ($c) => [$c], ['no customer', 'insufficient', 'pending balance', 'unsupported', 'foreign', 'inactive', 'approval', 'staff', 'exceeds', 'reserved']);
    }

    #[DataProvider('refused')]
    public function test_refusals_do_not_change_rows(string $cause): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule, $account] = $this->loyalty($o);
        $intent = $this->redeem($rule);
        $code = 'loyalty_rule_unsupported';
        switch ($cause) {
            case 'no customer': $o->update(['customer_id' => null]);
                $code = 'loyalty_no_customer';
                break;
            case 'insufficient': $account->update(['point_balance' => 99]);
                $code = 'loyalty_insufficient';
                break;
            case 'pending balance':
                $this->adjust($d, $s, $intent)->assertOk();
                $account->update(['point_balance' => 199]);
                $code = 'loyalty_insufficient';
                break;
            case 'unsupported': $rule->update(['type' => 'visit_based', 'config_json' => ['reward_type' => 'free_product', 'reward_value' => 1, 'stamps_required' => 5]]);
                break;
            case 'foreign': $rule->update(['company_id' => 200]);
                break;
            case 'inactive': $rule->update(['status' => 'paused']);
                break;
            case 'approval': unset($intent['authorized_by']);
                $code = 'approval_required';
                break;
            case 'staff': unset($intent['approved_by_staff_id']);
                $code = 'approval_required';
                break;
            case 'exceeds': $intent['blocks'] = 9;
                $code = 'adjustment_exceeds_bill';
                break;
            case 'reserved': $this->postAs($d, 'qr/claim-settlement', ['order_uuid' => $o->uuid])->assertOk();
                $code = 'bill_reserved';
                break;
        }
        $before = DB::table('pos_order_discounts')->get()->toJson();
        $this->adjust($d, $s, $intent)->assertConflict()->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, DB::table('pos_order_discounts')->get()->toJson());
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_customer_changes_and_item_removal_clear_whole_pending_redeem(): void
    {
        [$d, $s, $o, $round] = $this->fixture();
        [$rule] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule, 8))->assertOk();
        $original = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_loyalty_redeem')->sole();
        $p = $this->intent($s, []);
        unset($p['adjustment']);
        $p += ['product_id' => $round->priced_lines[0]['product_id'], 'addon_ids' => [], 'notes' => null, 'qty' => 1,
            'prepared' => false, 'cancelled_at' => now()->toIso8601String()];
        $this->postAs($d, 'tables/'.$s->uuid.'/cancel-line', $p)->assertOk();
        $this->assertEquals(0, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->sum('amount'));
        $this->assertEquals($original, DB::table('pos_order_discounts')->where('id', $original->id)->sole());
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        $other = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'New', 'phone' => '90000002']);
        $this->adjust($d, $s, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $other->id])->assertOk();
        $this->assertEquals(0, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->sum('amount'));
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_customer_three_per_oman_day_with_counter_redeem_and_next_day_reset(): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule, $a, $c] = $this->loyalty($o);
        $this->travelTo(Carbon::parse('2026-09-22 19:59:00', 'UTC'));
        $counter = $this->seatingOrder($this->seatingRow($this->seatingTable()), ['table_session_id' => null, 'customer_id' => $c->id, 'status' => 'paid']);
        LoyaltyTransaction::create(['uuid' => Str::uuid(), 'company_id' => 100, 'loyalty_account_id' => $a->id, 'type' => 'redeem', 'points_delta' => -100, 'stamps_delta' => 0,
            'balance_after_points' => 2000, 'balance_after_stamps' => 100, 'order_id' => $counter->id, 'occurred_at' => now()]);
        for ($i = 0; $i < 2; $i++) {
            $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        }
        $this->adjust($d, $s, $this->redeem($rule))->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_customer_limit');
        $this->travelTo(Carbon::parse('2026-09-22 20:01:00', 'UTC'));
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
    }

    public function test_staff_ten_per_shift_then_other_staff_and_new_shift(): void
    {
        $shift = Shift::create(['uuid' => Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => null, 'staff_id' => 7, 'status' => 'open', 'opened_at' => now()->subHour()]);
        for ($i = 0; $i < 10; $i++) {
            [$d, $s, $o] = $this->fixture();
            [$rule] = $this->loyalty($o);
            $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        }
        [$d, $s, $o] = $this->fixture();
        [$rule] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule))->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_staff_limit');
        $this->adjust($d, $s, $this->redeem($rule, 1, 8))->assertOk();
        $this->travel(1)->seconds();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        Shift::create(['uuid' => Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $d->id, 'staff_id' => 7, 'status' => 'open', 'opened_at' => now()]);
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
    }

    public function test_route_limiter_burst_does_not_limit_other_adjustment_kinds(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        [$d, $s, $o] = $this->fixture();
        [$rule] = $this->loyalty($o);
        for ($i = 0; $i < 10; $i++) {
            $this->adjust($d, $s, ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        }
        $this->adjust($d, $s, $this->redeem($rule))->assertStatus(429);
        $this->adjust($d, $s, ['kind' => 'discount', 'mode' => 'clear'])->assertOk();
        $this->travel(61)->seconds();
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
    }

    public function test_payment_debits_saved_row_once_ignores_payload_and_void_reverses(): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule, $a] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule, 2))->assertOk();
        $this->postAs($d, 'qr/claim-settlement', ['order_uuid' => $o->uuid])->assertOk()->assertJsonPath('data.charge_amount_baisas', 3623);
        $id = (string) Str::uuid();
        $this->pay($d, $o, $id);
        $this->pay($d, $o, $id);
        $tx = LoyaltyTransaction::where('order_id', $o->id)->where('type', 'redeem')->sole();
        $this->assertSame(-200, $tx->points_delta);
        $this->assertSame(34, (int) LoyaltyTransaction::where('order_id', $o->id)->where('type', 'earn')->sum('points_delta'));
        $this->assertSame(1834, $a->fresh()->point_balance);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->postAs($d, 'sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $o->uuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'T12 synthetic', 'authorized_by' => 'Manager'],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(2000, $a->fresh()->point_balance);
        $this->assertEquals([1.0, -1.0], DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->orderBy('id')->pluck('amount')->all());
    }

    public function test_payment_preserves_existing_insufficient_balance_clamp(): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule, $a] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule, 2))->assertOk();
        $a->update(['point_balance' => 0]);
        $this->postAs($d, 'qr/claim-settlement', ['order_uuid' => $o->uuid])->assertOk();
        $this->pay($d, $o);
        $this->assertSame(-34, (int) LoyaltyTransaction::where('order_id', $o->id)->where('type', 'redeem')->sum('points_delta'));
        $this->assertSame(0, $a->fresh()->point_balance);
        $this->assertSame('paid', $o->fresh()->status);
    }

    public function test_cancel_bill_reverses_pending_redemption_without_ledger_debit(): void
    {
        [$d, $s, $o, $round] = $this->fixture();
        [$rule] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        $p = $this->intent($s, []);
        unset($p['adjustment']);
        $p += ['staff_id' => 7, 'authorized_by' => 'Manager', 'reason' => 'T12 synthetic', 'cancelled_at' => now()->toIso8601String(),
            'lines' => array_map(fn ($l) => ['client_request_id' => (string) Str::uuid(), 'product_id' => $l['product_id'], 'addon_ids' => [], 'notes' => null, 'qty' => $l['qty'], 'prepared' => false], $round->priced_lines)];
        $this->postAs($d, 'tables/'.$s->uuid.'/cancel-bill', $p)->assertOk();
        $this->assertSame('void', $o->fresh()->status);
        $this->assertEquals([.5, -.5], DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_manual_comp_and_loyalty_share_one_header_and_stamps_pay_once(): void
    {
        [$d, $s, $o, $round, $reason] = $this->fixture();
        [$rule, $a] = $this->loyalty($o, 'visit_based');
        $this->adjust($d, $s, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service'])->assertOk();
        $this->adjust($d, $s, ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id, 'authorized_by' => 'Manager',
            'target' => ['order_item_id' => $round->priced_lines[0]['order_item_id'], 'qty' => 1]])->assertOk();
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        // net=4450 - manual500 - comp900 - stamp750 =2300; tax=round(223*2300/4450)=115.
        $this->assertSame(['5.000', '1.800', '0.900', '0.115', '2.415'], $this->money($o));
        fwrite(STDOUT, "\nT12_COMBINED_GOLDEN=".json_encode($this->money($o))."\n");
        $this->postAs($d, 'qr/claim-settlement', ['order_uuid' => $o->uuid])->assertOk()->assertJsonPath('data.charge_amount_baisas', 2415);
        $id = (string) Str::uuid();
        $this->pay($d, $o, $id);
        $this->pay($d, $o, $id);
        $this->assertSame(-5, LoyaltyTransaction::where('order_id', $o->id)->where('type', 'redeem')->sole()->stamps_delta);
        $this->assertSame(96, $a->fresh()->stamp_count);
    }

    public function test_detach_is_append_only_and_does_not_reset_original_customer_daily_usage(): void
    {
        [$d, $s, $o] = $this->fixture();
        [$rule, $a, $customer] = $this->loyalty($o);
        for ($i = 0; $i < 3; $i++) {
            $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        }
        $positive = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_loyalty_redeem')->orderBy('id')->get()->toJson();
        $this->adjust($d, $s, ['kind' => 'customer', 'mode' => 'detach'])->assertOk();
        $this->assertEquals(0, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->sum('amount'));
        $this->assertSame($positive, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_loyalty_redeem')->orderBy('id')->get()->toJson());
        [$d2, $s2, $o2] = $this->fixture();
        $o2->update(['customer_id' => $customer->id]);
        $this->adjust($d2, $s2, $this->redeem($rule))->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_customer_limit');
        $this->assertSame(2000, $a->fresh()->point_balance);
    }

    public function test_no_shift_staff_day_resets_and_blocks_validation_stays_whole(): void
    {
        for ($i = 0; $i < 10; $i++) {
            [$d, $s, $o] = $this->fixture();
            [$rule] = $this->loyalty($o);
            $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        }
        [$d, $s, $o] = $this->fixture();
        [$rule] = $this->loyalty($o);
        $this->adjust($d, $s, $this->redeem($rule))->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_staff_limit');
        $this->travelTo(Carbon::parse('2026-09-22 20:01:00', 'UTC'));
        $this->adjust($d, $s, $this->redeem($rule))->assertOk();
        foreach ([0, 51, 1.5] as $blocks) {
            $intent = $this->redeem($rule);
            $intent['blocks'] = $blocks;
            $this->adjust($d, $s, $intent)->assertUnprocessable();
        }
        $this->assertSame(1, DB::table('pos_order_discounts')->where('order_id', $o->id)->where('amount_type_snapshot', 'table_loyalty_redeem')->count());
    }
}
