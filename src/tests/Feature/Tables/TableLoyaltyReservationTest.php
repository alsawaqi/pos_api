<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\CompReason;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableLoyaltyReservationTest extends TestCase
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

    private function fixture(int $branch = 10): array
    {
        $device = $this->seatingDevice(branchId: $branch);
        $seat = $this->seatingRow($this->seatingTable(branchId: $branch), ['opened_by_device_id' => $device->id]);
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
            DB::table('pos_order_discounts')->insert(['company_id' => 100, 'branch_id' => $branch, 'order_id' => $order->id,
                'order_item_id' => $itemId, 'name_snapshot' => 'Frozen rule', 'amount_type_snapshot' => 'percent', 'amount' => $amount / 1000]);
        }
        $reason = CompReason::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'code' => 'T65', 'name' => 'Service recovery', 'is_active' => true]);

        return [$device, $seat, $order, $round, $reason];
    }

    private function postAs($device, string $path, array $data)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/'.$path, $data);
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

    private function pair(string $kind = 'spend_based', int $balance = 200): array
    {
        [$d1, $s1, $o1, $round1] = $this->fixture();
        [$d2, $s2, $o2] = $this->fixture();
        [$rule, $account, $customer] = $this->loyalty($o1, $kind);
        $account->update([$kind === 'spend_based' ? 'point_balance' : 'stamp_count' => $balance]);
        $o2->update(['customer_id' => $customer->id]);

        return [$d1, $s1, $o1, $round1, $d2, $s2, $o2, $rule, $account, $customer];
    }

    private function assertUnavailable($device, $seat, $rule, int $blocks = 2): void
    {
        $before = [];
        foreach (['pos_order_discounts', 'pos_table_session_events', 'pos_loyalty_transactions', 'pos_orders'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->adjust($device, $seat, $this->redeem($rule, $blocks))
            ->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_insufficient');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed on refusal');
        }
    }

    public static function unpaidStates(): array
    {
        return [['open'], ['held'], ['awaiting_payment']];
    }

    #[DataProvider('unpaidStates')]
    public function test_pending_units_on_other_unpaid_bills_block_redemption_without_writes(string $status): void
    {
        [$d1, $s1, $o1, , $d2, $s2, , $rule, $account] = $this->pair();
        $this->adjust($d1, $s1, $this->redeem($rule, 2))->assertOk();
        $o1->update(['status' => $status]);
        $this->assertUnavailable($d2, $s2, $rule);
        $this->assertSame(200, $account->fresh()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public static function releases(): array
    {
        return array_map(fn ($mode) => [$mode], ['clear', 'cancel', 'void', 'detach', 'attach', 'remove']);
    }

    #[DataProvider('releases')]
    public function test_reversal_or_terminal_bill_frees_units_for_another_bill(string $mode): void
    {
        $blocks = $mode === 'remove' ? 8 : 2;
        [$d1, $s1, $o1, $round1, $d2, $s2, , $rule, $account] = $this->pair('spend_based', $blocks * 100);
        $this->adjust($d1, $s1, $this->redeem($rule, $blocks))->assertOk();
        $original = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_loyalty_redeem')->sole();
        $this->assertUnavailable($d2, $s2, $rule, $blocks);
        if ($mode === 'void') {
            $this->postAs($d1, 'sync/push', ['events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void', 'client_timestamp' => now()->toIso8601String(),
                'payload' => ['order_uuid' => $o1->uuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'F35 synthetic', 'authorized_by' => 'Manager'],
            ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        } elseif ($mode === 'cancel' || $mode === 'remove') {
            $payload = $this->intent($s1, []);
            unset($payload['adjustment']);
            $line = $round1->priced_lines[0];
            if ($mode === 'cancel') {
                $payload += ['staff_id' => 7, 'authorized_by' => 'Manager', 'reason' => 'F35 synthetic', 'cancelled_at' => now()->toIso8601String(),
                    'lines' => array_map(fn ($l) => ['client_request_id' => (string) Str::uuid(), 'product_id' => $l['product_id'], 'addon_ids' => [], 'notes' => null, 'qty' => $l['qty'], 'prepared' => false], $round1->priced_lines)];
            } else {
                $payload += ['product_id' => $line['product_id'], 'addon_ids' => [], 'notes' => null, 'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String()];
            }
            $this->postAs($d1, 'tables/'.$s1->uuid.'/'.($mode === 'cancel' ? 'cancel-bill' : 'cancel-line'), $payload)->assertOk();
        } elseif ($mode === 'attach') {
            $customer = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Other', 'phone' => '95550001']);
            $this->adjust($d1, $s1, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $customer->id])->assertOk();
        } else {
            $this->adjust($d1, $s1, $mode === 'detach' ? ['kind' => 'customer', 'mode' => 'detach'] : ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        }
        $this->assertEquals($original, DB::table('pos_order_discounts')->where('id', $original->id)->sole());
        $this->assertEquals(0, DB::table('pos_order_discounts')->where('order_id', $o1->id)->where('amount_type_snapshot', 'like', 'table_loyalty_%')->sum('amount'));
        $this->adjust($d2, $s2, $this->redeem($rule, $blocks))->assertOk();
        $this->assertSame($blocks * 100, $account->fresh()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_paid_bill_uses_lowered_balance_without_counting_its_pending_slot_again(): void
    {
        [$d1, $s1, $o1, , $d2, $s2, , $rule, $account] = $this->pair('spend_based', 300);
        $this->adjust($d1, $s1, $this->redeem($rule, 2))->assertOk();
        $this->assertUnavailable($d2, $s2, $rule);
        $this->pay($d1, $o1);
        $this->assertSame(134, $account->fresh()->point_balance);
        $this->assertUnavailable($d2, $s2, $rule);
        $this->adjust($d2, $s2, $this->redeem($rule, 1))->assertOk();
        $this->assertSame(134, $account->fresh()->point_balance);
    }

    public function test_pending_stamps_are_not_reusable_on_another_table(): void
    {
        [$d1, $s1, , , $d2, $s2, , $rule, $account] = $this->pair('visit_based', 10);
        $this->adjust($d1, $s1, $this->redeem($rule, 2))->assertOk();
        $this->assertUnavailable($d2, $s2, $rule);
        $this->adjust($d1, $s1, ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        $this->adjust($d2, $s2, $this->redeem($rule, 2))->assertOk();
        $this->assertSame(10, $account->fresh()->stamp_count);
    }

    public static function isolatedScopes(): array
    {
        return [['customer'], ['rule']];
    }

    #[DataProvider('isolatedScopes')]
    public function test_only_same_customer_and_same_rule_reserve_units(string $scope): void
    {
        [$d1, $s1, , , $d2, $s2, $o2, $rule, , $customer] = $this->pair();
        $this->adjust($d1, $s1, $this->redeem($rule, 2))->assertOk();
        $this->assertUnavailable($d2, $s2, $rule);
        if ($scope === 'customer') {
            $customer = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Other', 'phone' => '95550002']);
            $o2->update(['customer_id' => $customer->id]);
        } else {
            $rule = $rule->replicate();
            $rule->uuid = Str::uuid();
            $rule->save();
        }
        LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $customer->id,
            'loyalty_rule_id' => $rule->id, 'point_balance' => 200, 'stamp_count' => 0]);
        $this->adjust($d2, $s2, $this->redeem($rule, 2))->assertOk();
    }

    public function test_sum_of_other_slots_and_own_replacement_is_checked_through_sync(): void
    {
        [$d1, $s1, , , $d2, $s2, , $rule, $account] = $this->pair('spend_based', 300);
        $this->adjust($d1, $s1, $this->redeem($rule, 1))->assertOk();
        $this->adjust($d2, $s2, $this->redeem($rule, 1))->assertOk();
        $before = DB::table('pos_order_discounts')->orderBy('id')->get()->toJson();
        $this->postAs($d2, 'sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.adjust', 'client_timestamp' => now()->toIso8601String(),
            'payload' => $this->intent($s2, $this->redeem($rule, 2)),
        ]]])->assertOk()->assertJsonPath('data.results.0.result.refusal_code', 'loyalty_insufficient');
        $this->assertSame($before, DB::table('pos_order_discounts')->orderBy('id')->get()->toJson());
        $this->assertSame(300, $account->fresh()->point_balance);
    }

    public function test_f35_exact_two_bill_scenario_discounts_match_debited_points_without_clamp(): void
    {
        [$d1, $s1, $o1, , $d2, $s2, $o2, $rule, $account] = $this->pair();
        $a = $this->adjust($d1, $s1, $this->redeem($rule, 2));
        $b = $this->adjust($d2, $s2, $this->redeem($rule, 2));
        $a->assertOk();
        $this->pay($d1, $o1);
        $this->pay($d2, $o2);
        $debited = -(int) LoyaltyTransaction::where('type', 'redeem')->sum('points_delta');
        $discount = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_loyalty_%')->get()->sum(fn ($r): int => Money::toBaisas($r->amount));
        $clamps = LoyaltyTransaction::where('type', 'adjust')->count();
        fwrite(STDOUT, "\nF35_MEASURED=".json_encode(['first_http' => $a->status(), 'second_http' => $b->status(), 'second_code' => $b->json('errors.0.code'),
            'grand_totals' => [$o1->fresh()->grand_total, $o2->fresh()->grand_total], 'points_debited' => $debited, 'discount_baisas' => $discount,
            'points_value_baisas' => $debited * 5, 'balance' => $account->fresh()->point_balance, 'clamp_rows' => $clamps])."\n");
        $this->assertSame($debited * 5, $discount, 'Every loyalty discount must be backed by debited points.');
        $b->assertConflict()->assertJsonPath('errors.0.code', 'loyalty_insufficient');
        $this->assertSame(200, $debited);
        $this->assertSame(1000, $discount);
        $this->assertSame(0, $clamps);
        $this->assertSame(78, $account->fresh()->point_balance);
    }

    public function test_all_other_slots_are_summed_across_merchant_branches(): void
    {
        [$d1, $s1, , , $d2, $s2, , $rule, $account, $customer] = $this->pair('spend_based', 300);
        [$d3, $s3, $o3] = $this->fixture(20);
        $o3->update(['customer_id' => $customer->id]);
        $this->adjust($d1, $s1, $this->redeem($rule, 1))->assertOk();
        $this->adjust($d2, $s2, $this->redeem($rule, 1))->assertOk();
        $this->assertUnavailable($d3, $s3, $rule, 2);
        $this->adjust($d1, $s1, ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        $this->adjust($d3, $s3, $this->redeem($rule, 2))->assertOk();
        $this->assertSame(300, $account->fresh()->point_balance);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }
}
