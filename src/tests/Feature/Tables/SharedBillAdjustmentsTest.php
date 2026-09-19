<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Models\CompReason;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\OrderItem;
use App\Models\Table;
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

final class SharedBillAdjustmentsTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-19 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
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

    public static function golden(): array
    {
        return [
            'percent 10%' => [['kind' => 'discount', 'mode' => 'percent', 'percent_bp' => 1000, 'label' => 'Ten'], ['5.000', '0.995', '0.000', '0.201', '4.206'], 445],
            'fixed 500' => [['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service'], ['5.000', '1.050', '0.000', '0.198', '4.148'], 500],
            'round half baisa' => [['kind' => 'discount', 'mode' => 'percent', 'percent_bp' => 500, 'label' => 'Five'], ['5.000', '0.773', '0.000', '0.212', '4.439'], 223],
        ];
    }

    #[DataProvider('golden')]
    public function test_golden_arithmetic_preserves_rounds_and_original_audit(array $intent, array $expected, int $amount): void
    {
        [$device, $seat, $order, $round] = $this->fixture();
        $original = $round->refresh()->getRawOriginal();
        $audit = DB::table('pos_order_discounts')->orderBy('id')->get()->toArray();
        $this->adjust($device, $seat, $intent)->assertOk()->assertJsonPath('data.outcome', 'adjusted');
        $this->assertSame($expected, $this->money($order));
        $this->assertSame($original, $round->refresh()->getRawOriginal());
        $this->assertEquals($audit, DB::table('pos_order_discounts')->whereIn('id', array_column($audit, 'id'))->orderBy('id')->get()->toArray());
        $proof = TableSessionEvent::query()->where('event_type', 'adjusted')->sole()->payload;
        $this->assertSame($amount, $proof['amount_baisas']);
        $this->assertSame(4450, $proof['basis_baisas']);
        $this->assertArrayNotHasKey('label', $proof);
        $this->assertArrayNotHasKey('priced_lines', $proof);
    }

    public function test_comp_and_discount_replace_and_clear_are_append_only(): void
    {
        [$device, $seat, $order, $round, $reason] = $this->fixture();
        $original = $round->refresh()->getRawOriginal();
        $comp = ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id,
            'target' => ['order_item_id' => $round->priced_lines[0]['order_item_id'], 'qty' => 1], 'authorized_by' => 'Manager'];
        $this->adjust($device, $seat, $comp)->assertOk();
        $this->assertSame(['5.000', '0.550', '0.900', '0.178', '3.728'], $this->money($order));
        $row = DB::table('pos_order_comps')->sole();
        $fixed = ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service'];
        $this->adjust($device, $seat, $fixed)->assertOk();
        $this->assertSame(['5.000', '1.050', '0.900', '0.153', '3.203'], $this->money($order));
        $this->adjust($device, $seat, $comp)->assertOk();
        $this->assertEquals($row, DB::table('pos_order_comps')->where('id', $row->id)->sole());
        $this->assertEquals([0.9, -0.9, 0.9], DB::table('pos_order_comps')->orderBy('id')->pluck('amount')->all());
        $this->adjust($device, $seat, ['kind' => 'comp', 'mode' => 'clear'])->assertOk();
        $this->adjust($device, $seat, array_replace($fixed, ['amount_baisas' => 300]))->assertOk();
        $this->assertSame(['5.000', '0.850', '0.000', '0.208', '4.358'], $this->money($order));
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'clear'])->assertOk();
        $this->assertSame(['5.000', '0.550', '0.000', '0.223', '4.673'], $this->money($order));
        $this->assertSame($original, $round->refresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_order_comps', 4);
    }

    public function test_both_transports_replay_same_identity_and_conflict_does_not_write(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $payload = $this->intent($seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service']);
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $payload)->assertOk();
        $before = $this->money($order);
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.adjust',
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.outcome', 'replayed');
        $payload['adjustment']['amount_baisas'] = 501;
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $payload)->assertConflict()->assertJsonPath('errors.0.code', 'adjust_request_conflict');
        $this->assertSame($before, $this->money($order));
        $this->assertDatabaseCount('pos_order_discounts', 3);
        $this->assertSame(1, TableSessionEvent::where('event_type', 'adjusted')->count());
    }

    public function test_validation_approval_caps_and_tenant_refusals_have_no_audit_effect(): void
    {
        [$device, $seat, $order, $round, $reason] = $this->fixture();
        $comp = ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id,
            'target' => ['order_item_id' => $round->priced_lines[0]['order_item_id'], 'qty' => 1]];
        $this->adjust($device, $seat, $comp)->assertConflict()->assertJsonPath('errors.0.code', 'approval_required');
        $comp['authorized_by'] = 'Manager';
        $this->adjust($device, $seat, array_replace($comp, ['target' => 'bill']))->assertConflict()->assertJsonPath('errors.0.code', 'full_comp_not_supported');
        $reason->update(['max_amount' => '.100']);
        $this->adjust($device, $seat, $comp)->assertConflict()->assertJsonPath('errors.0.code', 'comp_cap_exceeded');
        $bad = ['kind' => 'discount', 'mode' => 'percent', 'percent_bp' => 10000, 'label' => 'All'];
        $this->adjust($device, $seat, $bad)->assertConflict()->assertJsonPath('errors.0.code', 'adjustment_exceeds_bill');
        $this->adjust($device, $seat, $bad + ['grand_total_baisas' => 1])->assertUnprocessable();
        $p = $this->intent($seat, ['kind' => 'discount', 'mode' => 'clear']);
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $p + ['price' => 1])->assertUnprocessable();
        $other = $this->seatingDevice('handheld', 20, 200);
        $this->postAs($other, 'tables/'.$seat->uuid.'/adjust', $p)->assertNotFound();
        $this->assertDatabaseCount('pos_order_discounts', 2);
        $this->assertDatabaseCount('pos_order_comps', 0);
        $this->assertSame(['5.000', '0.550', '0.000', '0.223', '4.673'], $this->money($order));
    }

    public function test_rule_uses_server_value_and_branch_validity_and_manager_requirement(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $rule = Discount::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Five', 'scope' => 'order',
            'amount_type' => 'percent', 'amount' => 5, 'status' => 'active', 'requires_manager_approval' => true, 'branch_scope_json' => [10]]);
        $intent = ['kind' => 'discount', 'mode' => 'rule', 'discount_id' => $rule->id];
        $this->adjust($device, $seat, $intent)->assertConflict()->assertJsonPath('errors.0.code', 'approval_required');
        $this->adjust($device, $seat, $intent + ['authorized_by' => 'Manager'])->assertOk();
        $this->assertSame(['5.000', '0.773', '0.000', '0.212', '4.439'], $this->money($order));
        $rule->update(['branch_scope_json' => [20]]);
        $this->adjust($device, $seat, $intent + ['authorized_by' => 'Manager'])->assertConflict()->assertJsonPath('errors.0.code', 'discount_rule_not_applicable');
    }

    public function test_cancel_line_preserves_its_frozen_math_and_clamps_only_manual_audit(): void
    {
        [$device, $seat, $order, $round] = $this->fixture();
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 4400, 'label' => 'Almost all'])->assertOk();
        $first = DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_manual_fixed')->sole();
        $p = $this->intent($seat, []);
        unset($p['adjustment']);
        $p += ['product_id' => $round->priced_lines[0]['product_id'], 'addon_ids' => [], 'notes' => null,
            'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String()];
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $p)->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        $this->assertSame([4000, 180, 3778], array_values($round->refresh()->only(['subtotal_baisas', 'tax_baisas', 'total_baisas'])));
        $this->assertSame(['4.000', '3.999', '0.000', '0.000', '0.001'], $this->money($order));
        $this->assertEquals($first, DB::table('pos_order_discounts')->where('id', $first->id)->sole());
        $this->assertEquals(-.803, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'table_manual_clamp')->value('amount'));
    }

    public function test_adjusted_charge_snapshot_and_pay_use_exact_amount_and_paid_seating_closes(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service'])->assertOk();
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk()->assertJsonPath('data.charge_amount_baisas', 4148);
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'clear'])->assertConflict()->assertJsonPath('errors.0.code', 'bill_reserved');
        $this->withToken($device->device_token)->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/checkout')->assertOk()
            ->assertJsonPath('data.order.manual_discount_baisas', 500)->assertJsonPath('data.claim.charge_amount_baisas', 4148);
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(), 'loyalty_rule_ids' => [999],
                'payments' => [['method' => 'cash', 'amount_baisas' => 4147]]]];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertDatabaseCount('pos_payments', 0);
        $event['client_event_id'] = (string) Str::uuid();
        $event['payload']['payments'][0]['amount_baisas'] = 4148;
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame('closed', $seat->refresh()->status);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_customer_attach_detach_tenant_scope_and_earn_all_active_company_rules(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $customer = Customer::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'T65 synthetic', 'phone' => '90000001']);
        $other = Customer::query()->create(['uuid' => Str::uuid(), 'company_id' => 200, 'name' => 'T65 other', 'phone' => '90000002']);
        $this->adjust($device, $seat, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $other->id])
            ->assertConflict()->assertJsonPath('errors.0.code', 'customer_not_found');
        $this->adjust($device, $seat, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $customer->id])->assertOk();
        $this->assertSame($customer->id, $order->refresh()->customer_id);
        $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$seat->table_id.'/detail')->assertOk()
            ->assertJsonPath('data.bill.customer.id', $customer->id)->assertJsonPath('data.bill.customer.name', 'T65 synthetic')
            ->assertJsonMissingPath('data.bill.customer_id')->assertJsonMissingPath('data.bill.plate_number');
        $this->adjust($device, $seat, ['kind' => 'customer', 'mode' => 'detach'])->assertOk();
        $this->assertNull($order->refresh()->customer_id);
        $this->adjust($device, $seat, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $customer->id])->assertOk();
        foreach ([[100, 'active'], [100, 'active'], [100, 'paused'], [200, 'active']] as $i => [$company, $status]) {
            DB::table('pos_loyalty_rules')->insert(['id' => $i + 1, 'uuid' => Str::uuid(), 'company_id' => $company,
                'name' => 'T65 earn '.$i, 'type' => 'visit_based', 'status' => $status,
                'config_json' => json_encode(['min_order_value' => '0.001', 'stamps_required' => 5])]);
        }
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(), 'loyalty_rule_ids' => [4, 999],
                'payments' => [['method' => 'cash', 'amount_baisas' => 4673]]]];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertEquals([1, 2], DB::table('pos_loyalty_transactions')->join('pos_loyalty_accounts', 'pos_loyalty_accounts.id', '=', 'pos_loyalty_transactions.loyalty_account_id')
            ->where('order_id', $order->id)->orderBy('loyalty_rule_id')->pluck('loyalty_rule_id')->all());
        $journal = TableSessionEvent::where('event_type', 'adjusted')->get()->toJson();
        $this->assertStringNotContainsString('90000001', $journal);
        $this->assertStringNotContainsString('T65 synthetic', $journal);
    }

    public function test_reopen_then_adjust_and_charge_integrity_rejects_corrupt_header(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $order->update(['client_event_id' => (string) Str::uuid()]);
        app(EnsureLegacyTableBillBaselineAction::class)->assertReadyForCharge($order);
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'fixed', 'label' => 'Service', 'amount_baisas' => 500])->assertOk();
        app(EnsureLegacyTableBillBaselineAction::class)->assertReadyForCharge($order->refresh());
        $order->update(['grand_total' => '4.149']);
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid])->assertConflict();
        $order->update(['grand_total' => '4.148']);
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        $this->postAs($device, 'qr/release-charge', ['order_uuid' => $order->uuid, 'outcome' => 'cancelled'])->assertOk();
        $this->postAs($device, 'qr/reopen-payment', ['order_uuid' => $order->uuid])->assertOk();
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'clear'])->assertOk();
        $this->assertSame(['5.000', '0.550', '0.000', '0.223', '4.673'], $this->money($order));
    }

    public function test_later_round_keeps_frozen_discount_and_marks_basis_stale_in_real_detail(): void
    {
        [$device, $seat, $order] = $this->fixture();
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'percent', 'percent_bp' => 1000, 'label' => 'Ten'])->assertOk();
        $product = $this->seatingProduct();
        $p = $this->intent($seat, []);
        unset($p['adjustment']);
        $p += ['submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => []]]];
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $p)->assertOk()->assertJsonPath('data.round_status', 'accepted');
        $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$seat->table_id.'/detail')->assertOk()
            ->assertJsonPath('data.bill.manual_discount_baisas', 445)->assertJsonPath('data.bill.adjustment_state.discount.stale', true)
            ->assertJsonPath('data.bill.adjustment_state.discount.basis_baisas', 4450);
    }

    public function test_cancel_line_keeps_a_frozen_manual_amount_without_clamping(): void
    {
        [$device, $seat, $order, $round] = $this->fixture();
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Service'])->assertOk();
        $p = $this->intent($seat, []);
        unset($p['adjustment']);
        $p += ['product_id' => $round->priced_lines[0]['product_id'], 'addon_ids' => [], 'notes' => null,
            'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String()];
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $p)->assertOk();
        $this->assertSame([4000, 180, 3778], array_values($round->refresh()->only(['subtotal_baisas', 'tax_baisas', 'total_baisas'])));
        $this->assertSame(['4.000', '0.902', '0.000', '0.155', '3.253'], $this->money($order));
        $this->assertDatabaseMissing('pos_order_discounts', ['amount_type_snapshot' => 'table_manual_clamp']);
    }

    public function test_sync_apply_and_public_adjusted_totals_keep_frozen_rounds_and_private_identity_private(): void
    {
        [$device, $seat, $order, $round, $reason] = $this->fixture();
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $intent = $this->intent($seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 500, 'label' => 'Private discount', 'reason' => 'Private reason']);
        $this->postAs($device, 'sync/push', ['events' => [[
            'client_event_id' => $intent['client_request_id'], 'event_type' => 'table.session.adjust',
            'client_timestamp' => now()->toIso8601String(), 'payload' => $intent,
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.outcome', 'adjusted');
        $this->assertSame(['5.000', '1.050', '0.000', '0.198', '4.148'], $this->money($order));
        $this->adjust($device, $seat, ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id,
            'target' => ['order_item_id' => $round->priced_lines[0]['order_item_id'], 'qty' => 1], 'authorized_by' => 'Manager'])->assertOk();
        $customer = Customer::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Private customer', 'phone' => '90000003']);
        $this->adjust($device, $seat, ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $customer->id])->assertOk();
        $bind = app(BindQrTableSessionAction::class)->handle(Table::findOrFail($seat->table_id)->qr_token, 't65-owner');
        $status = $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 't65-owner'])
            ->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.dine_in.running_total_baisas', 3203)
            ->assertJsonPath('data.dine_in.bill_totals', ['manual_discount_baisas' => 500, 'subtotal_baisas' => 5000,
                'discount_total_baisas' => 1050, 'comp_total_baisas' => 900, 'tax_total_baisas' => 153, 'grand_total_baisas' => 3203])
            ->assertJsonPath('data.dine_in.rounds.0.total_baisas', 4673);
        foreach (['Private customer', '90000003', 'Private discount', 'Private reason', 'Service recovery'] as $private) {
            $this->assertStringNotContainsString($private, $status->getContent());
        }
    }
}
