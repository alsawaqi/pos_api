<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\Sync\Handlers\CloseShiftHandler;
use App\Actions\Device\Sync\Handlers\VoidOrderHandler;
use App\Actions\Orders\VoidOrderCoreAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PosStaff;
use App\Models\Shift;
use App\Models\SyncEvent;
use App\Models\VoidReason;
use App\Support\SoftPos\ReversalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Pay002ReversalsTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private Order $order;

    private Payment $payment;

    private PosStaff $manager;

    private VoidReason $reason;

    private array $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-13 19:59:00', 'UTC'));
        $this->device = Device::factory()->withSoftPos()->paired('pay002-reversals')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos', 'terminal_id' => 'T1',
        ]);
        $this->manager = PosStaff::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Manager', 'position' => 'manager', 'status' => 'active', 'pin_hash' => Hash::make('123456'),
        ]);
        $this->reason = VoidReason::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Not prepared', 'code' => 'NOT_MADE', 'is_active' => true, 'affects_inventory' => false]);
        $this->order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id,
            'staff_id' => $this->manager->id, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid',
            'subtotal' => 10, 'discount_total' => 1, 'comp_total' => 1, 'tax_total' => '.375',
            'grand_total' => '8.375', 'opened_at' => now()->subHour(), 'closed_at' => now()->subMinute(),
        ]);
        foreach ([[1, 'unit', 2, 6], [2, 'ingredient', 1, 4]] as [$id, $mode, $qty, $total]) {
            DB::table('pos_products')->insert([
                'id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Product '.$id,
                'base_price' => $total / $qty, 'stock_mode' => $mode, 'status' => 'active',
            ]);
            DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $id, 'stock_qty' => 5]);
            $this->items[] = OrderItem::create([
                'order_id' => $this->order->id, 'product_id' => $id, 'product_name_snapshot' => 'Product '.$id,
                'qty' => $qty, 'unit_price_snapshot' => $total / $qty, 'line_discount' => 0,
                'line_total' => $total, 'status' => 'open', 'component_snapshot_json' => [],
            ]);
        }
        $this->payment = Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $this->order->id, 'device_id' => $this->device->id,
            'method' => 'card', 'status' => 'success', 'direction' => 'sale', 'amount' => '8.375',
            'roundup_amount' => '.125', 'bank_id' => $this->device->bank_id, 'terminal_id' => 'T1',
            'softpos_provider' => 'mosambee_dhofar', 'softpos_package' => 'com.mosambee.dhofar.softpos',
            'softpos_transaction_id' => 'ORIGINAL-1', 'softpos_auth_code' => 'AUTH-SALE',
            'captured_at' => now()->subMinute(),
        ]);
        $this->withToken('pay002-reversals');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'refund', 'manager_pin' => '123456', 'client_request_id' => (string) Str::uuid(),
            'reason_code' => 'RETURN', 'custom_amount_baisas' => 1000,
        ];
    }

    private function reserve(array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/device/payments/'.$this->payment->uuid.'/reversals', $payload);
    }

    private function submitResult(string $uuid, string $status = 'approved', array $extra = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/device/payments/reversals/'.$uuid.'/result', $extra + [
            'status' => $status, 'client_request_id' => (string) Str::uuid(), 'response_code' => '00',
            'auth_code' => 'AUTH-REV', 'reversal_transaction_id' => 'REV-TX', 'receipt_json' => ['status' => $status],
        ]);
    }

    public static function refusals(): array
    {
        return array_map(static fn ($case) => [$case], [
            'station', 'tablet', 'unassigned', 'inactive', 'blocked', 'cash', 'pending', 'failed',
            'reversal', 'voided', 'unpaid', 'bank', 'provider', 'profile_missing', 'profile_inactive',
            'pin', 'non_manager', 'foreign_manager', 'reference', 'balance', 'reason',
            'no_pin', 'profile_none', 'foreign_reason', 'inactive_reason', 'line_overflow', 'line_foreign', 'amount_xor',
        ]);
    }

    #[DataProvider('refusals')]
    public function test_refusals_leave_every_payment_order_and_stock_row_unchanged(string $case): void
    {
        $payload = $this->payload();
        $code = 'payment_not_reversible';
        $http = 409;
        switch ($case) {
            case 'no_pin': unset($payload['manager_pin']);
                $code = 'invalid_manager_pin';
                $http = 401;
                break;
            case 'profile_none': DB::table('pos_bank_softpos_profiles')->update(['softpos_provider' => 'none']);
                $code = 'reversal_bank_mismatch';
                break;
            case 'foreign_reason':
            case 'inactive_reason':
                $this->reason->update($case === 'foreign_reason' ? ['company_id' => 200] : ['is_active' => false]);
                $payload['kind'] = 'void';
                $payload['void_reason_id'] = $this->reason->id;
                $code = 'void_reason_invalid';
                $http = 422;
                break;
            case 'line_overflow':
            case 'line_foreign':
            case 'amount_xor':
                if ($case !== 'amount_xor') {
                    unset($payload['custom_amount_baisas']);
                }
                $payload['lines'] = [['order_item_id' => $case === 'line_foreign' ? 999 : $this->items[0]->id, 'qty' => 3]];
                $code = $case === 'amount_xor' ? 'refund_amount_source_invalid' : 'refund_qty_exceeds_sold';
                $http = $case === 'amount_xor' ? 422 : 409;
                break;
            case 'station': $this->device->forceFill(['device_type' => 'payment_station'])->save();
                $code = 'device_not_attended';
                break;
            case 'tablet': $this->device->forceFill(['device_type' => 'customer_tablet'])->save();
                $code = 'device_not_attended';
                break;
            case 'unassigned': $this->device->forceFill(['branch_id' => null])->save();
                $code = 'device_not_attended';
                break;
            case 'inactive': $this->device->forceFill(['status' => 'inactive'])->save();
                $code = 'device_not_attended';
                break;
            case 'blocked': $this->device->forceFill(['card_tenders_blocked_reason' => 'softpos_mismatch'])->save();
                $code = 'softpos_blocked';
                break;
            case 'cash': $this->payment->update(['method' => 'cash']);
                break;
            case 'pending': $this->payment->update(['pending_reconciliation' => true]);
                break;
            case 'failed': $this->payment->update(['status' => 'failed']);
                break;
            case 'reversal': $this->payment->update(['direction' => 'reversal']);
                break;
            case 'voided': $this->payment->update(['voided_at' => now()]);
                break;
            case 'unpaid': $this->order->update(['status' => 'open']);
                break;
            case 'bank': $this->payment->update(['bank_id' => 99]);
                $code = 'reversal_bank_mismatch';
                break;
            case 'provider': $this->payment->update(['softpos_provider' => 'mosambee_muscat']);
                $code = 'reversal_bank_mismatch';
                break;
            case 'profile_missing': DB::table('pos_bank_softpos_profiles')->delete();
                $code = 'reversal_bank_mismatch';
                break;
            case 'profile_inactive': DB::table('pos_bank_softpos_profiles')->update(['is_active' => false]);
                $code = 'reversal_bank_mismatch';
                break;
            case 'pin': $payload['manager_pin'] = '654321';
                $code = 'invalid_manager_pin';
                $http = 401;
                break;
            case 'non_manager': $this->manager->update(['position' => 'cashier']);
                $code = 'invalid_manager_pin';
                $http = 401;
                break;
            case 'foreign_manager': $this->manager->update(['company_id' => 200]);
                $code = 'invalid_manager_pin';
                $http = 401;
                break;
            case 'reference': $this->payment->update(['softpos_transaction_id' => null]);
                $code = 'original_reference_missing';
                break;
            case 'balance': $payload['custom_amount_baisas'] = 8376;
                $code = 'refund_exceeds_balance';
                break;
            case 'reason': $payload['kind'] = 'void';
                $payload['void_reason_id'] = 999;
                $code = 'void_reason_invalid';
                $http = 422;
                break;
        }
        $before = $this->states();
        if ($case === 'inactive') {
            $this->reserve($payload)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        } else {
            $this->reserve($payload)->assertStatus($http)->assertJsonPath('errors.0.code', $code);
        }
        $this->assertSame($before, $this->states());
        $this->assertDatabaseCount('pos_payment_reversals', 0);
        $this->assertDatabaseCount('pos_payment_reversal_lines', 0);
        $this->assertDatabaseCount('pos_payment_reversal_results', 0);
    }

    private function states(): array
    {
        return array_map(static fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), [
            'pos_orders', 'pos_payments', 'pos_branch_product', 'pos_product_stock_movements', 'pos_loyalty_transactions',
        ]);
    }

    public function test_reserve_and_result_replays_are_immutable_and_one_pending_blocks_a_second_request(): void
    {
        $payload = $this->payload();
        $first = $this->reserve($payload)->assertOk()->assertJsonPath('data.amount_baisas', 1000)->json('data');
        $this->reserve($payload)->assertOk()->assertExactJson(['data' => $first, 'meta' => [], 'errors' => []]);
        $this->reserve($this->payload())->assertStatus(409)->assertJsonPath('errors.0.code', 'reversal_in_progress');
        $payload['custom_amount_baisas']++;
        $this->reserve($payload)->assertStatus(409)->assertJsonPath('errors.0.code', 'idempotency_conflict');
        $request = (string) Str::uuid();
        $done = $this->submitResult($first['reversal_uuid'], 'approved', ['client_request_id' => $request])->assertOk()->json('data');
        $before = $this->states();
        $this->submitResult($first['reversal_uuid'], 'approved', ['client_request_id' => $request])->assertOk()->assertJsonPath('data', $done);
        $this->submitResult($first['reversal_uuid'], 'declined')->assertStatus(409)->assertJsonPath('errors.0.code', 'reversal_already_closed');
        $this->assertSame($before, $this->states());
        $this->assertDatabaseCount('pos_payments', 2);
        $this->assertDatabaseCount('pos_payment_reversal_results', 1);
        $this->assertSame('1.000', $this->payment->fresh()->refunded_total);
    }

    public function test_full_line_refund_apportions_discounts_comps_and_tax_and_returns_only_unit_goods(): void
    {
        $payload = $this->payload();
        unset($payload['custom_amount_baisas']);
        $payload['lines'] = [
            ['order_item_id' => $this->items[0]->id, 'qty' => 2],
            ['order_item_id' => $this->items[1]->id, 'qty' => 1],
        ];
        $uuid = $this->reserve($payload)->assertOk()->assertJsonPath('data.amount_baisas', 8375)->json('data.reversal_uuid');
        $this->assertSame([5025, 3350], DB::table('pos_payment_reversal_lines')->orderBy('order_item_id')->pluck('amount_baisas')->all());
        $this->submitResult($uuid)->assertOk();
        $this->assertSame('refunded', $this->order->fresh()->status);
        $this->assertSame('8.375', $this->payment->fresh()->refunded_total);
        $this->assertSame(8375, (int) round($this->order->fresh()->refunded_total * 1000));
        $this->assertSame('0.125', $this->payment->fresh()->roundup_amount);
        $this->assertDatabaseHas('pos_payments', ['direction' => 'reversal', 'amount' => '-8.375', 'softpos_auth_code' => 'AUTH-REV']);
        $this->assertDatabaseHas('pos_branch_product', ['product_id' => 1, 'stock_qty' => 7]);
        $this->assertDatabaseHas('pos_branch_product', ['product_id' => 2, 'stock_qty' => 5]);
        $this->assertDatabaseCount('pos_product_stock_movements', 1);
        $this->assertDatabaseHas('pos_product_stock_movements', ['movement_type' => 'refund_return', 'quantity' => 2, 'reference_type' => 'pos_payment_reversals']);
    }

    public function test_two_partial_quantities_then_overflow_are_capped_and_final_remaining_lines_sum_exactly(): void
    {
        $payload = $this->payload();
        unset($payload['custom_amount_baisas']);
        for ($i = 0; $i < 2; $i++) {
            $payload['client_request_id'] = (string) Str::uuid();
            $payload['lines'] = [['order_item_id' => $this->items[0]->id, 'qty' => 1]];
            $uuid = $this->reserve($payload)->assertOk()->json('data.reversal_uuid');
            $this->submitResult($uuid)->assertOk();
            $this->assertSame('paid', $this->order->fresh()->status);
        }
        $before = $this->states();
        $payload['client_request_id'] = (string) Str::uuid();
        $this->reserve($payload)->assertStatus(409)->assertJsonPath('errors.0.code', 'refund_qty_exceeds_sold');
        $this->assertSame($before, $this->states());
        $payload['lines'] = [['order_item_id' => $this->items[1]->id, 'qty' => 1]];
        $uuid = $this->reserve($payload)->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $this->assertSame('refunded', $this->order->fresh()->status);
        $this->assertSame('8.375', $this->payment->fresh()->refunded_total);
    }

    public function test_void_window_uses_muscat_midnight_and_never_subtracts_roundup_twice(): void
    {
        $payload = $this->payload(['kind' => 'void', 'void_reason_id' => $this->reason->id]);
        $uuid = $this->reserve($payload)->assertOk()->assertJsonPath('data.amount_baisas', 8375)->json('data.reversal_uuid');
        $this->submitResult($uuid, 'cancelled')->assertOk();
        $this->travelTo(Carbon::parse('2026-09-13 20:01:00', 'UTC'));
        $before = $this->states();
        $this->reserve($this->payload(['kind' => 'void', 'void_reason_id' => $this->reason->id]))
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'void_window_closed');
        $this->assertSame($before, $this->states());
    }

    public function test_uncertain_blocks_reversal_and_recovery_requires_receipt_and_same_device(): void
    {
        $uuid = $this->reserve($this->payload())->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid, 'uncertain')->assertOk();
        $this->assertTrue($this->payment->fresh()->bank_response['reversal_pending_review']);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->reserve($this->payload())->assertStatus(409)->assertJsonPath('errors.0.code', 'reversal_in_progress');
        $this->getJson('/api/v1/device/payments/reversals')->assertOk()->assertJsonCount(1, 'data.reversals');
        $this->submitResult($uuid, 'approved', ['receipt_json' => null])->assertStatus(409);
        Device::factory()->paired('another-pay002-device')->create(['company_id' => 100, 'branch_id' => 10, 'device_type' => 'handheld']);
        $this->withToken('another-pay002-device');
        $this->submitResult($uuid)->assertNotFound();
        $this->getJson('/api/v1/device/payments/reversals')->assertOk()->assertJsonCount(0, 'data.reversals');
        $this->withToken('pay002-reversals');
        $this->submitResult($uuid)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertArrayNotHasKey('reversal_pending_review', $this->payment->fresh()->bank_response);
        $this->assertDatabaseCount('pos_payments', 2);
    }

    public function test_declined_cancelled_and_stale_pending_have_no_financial_effects(): void
    {
        foreach (['declined', 'cancelled'] as $status) {
            $uuid = $this->reserve($this->payload())->assertOk()->json('data.reversal_uuid');
            $before = $this->states();
            $this->submitResult($uuid, $status)->assertOk();
            $this->assertSame($before, $this->states());
        }
        $uuid = $this->reserve($this->payload())->assertOk()->json('data.reversal_uuid');
        $this->travel(16)->minutes();
        $this->artisan('pos:reversals-sweep')->expectsOutput('Moved 1 stale reversal(s) to uncertain.')->assertExitCode(0);
        $this->assertDatabaseHas('pos_payment_reversals', ['uuid' => $uuid, 'status' => 'uncertain']);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->artisan('pos:reversals-sweep')->expectsOutput('Moved 0 stale reversal(s) to uncertain.')->assertExitCode(0);
    }

    public function test_muscat_unreferenced_refund_and_split_tender_cap(): void
    {
        DB::table('pos_bank_softpos_profiles')->update([
            'softpos_provider' => 'mosambee_muscat', 'softpos_package' => 'com.mosambee.muscat.softpos',
            'refund_needs_transaction_id' => false, 'void_needs_session_id' => true,
        ]);
        $this->payment->update(['softpos_provider' => 'mosambee_muscat', 'amount' => '3.000', 'softpos_transaction_id' => null]);
        Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $this->order->id, 'method' => 'cash',
            'amount' => '5.375', 'status' => 'success', 'captured_at' => now(),
        ]);
        $this->reserve($this->payload(['custom_amount_baisas' => 3001]))->assertStatus(409)->assertJsonPath('errors.0.code', 'refund_exceeds_balance');
        $uuid = $this->reserve($this->payload(['custom_amount_baisas' => 3000]))->assertOk()
            ->assertJsonPath('data.original_transaction_id', null)->assertJsonPath('data.softpos.needs_session', true)->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame('3.000', $this->payment->fresh()->refunded_total);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    public function test_options_and_tenant_boundary(): void
    {
        $this->getJson('/api/v1/device/orders/'.$this->order->uuid.'/payments')->assertOk()
            ->assertJsonPath('data.payments.0.can_void', true)->assertJsonPath('data.payments.0.can_refund', true)
            ->assertJsonPath('data.payments.0.refundable_baisas', 8375)
            ->assertJsonPath('data.payments.0.void_window_ends_at', '2026-09-14T00:00:00+04:00');
        $this->order->update(['company_id' => 200]);
        $before = $this->states();
        $this->reserve($this->payload())->assertNotFound();
        $this->getJson('/api/v1/device/orders/'.$this->order->uuid.'/payments')->assertNotFound();
        $this->assertSame($before, $this->states());
    }

    private function seedEffects(): void
    {
        $account = DB::table('pos_loyalty_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => 1,
            'loyalty_rule_id' => 1, 'point_balance' => 7, 'stamp_count' => 1,
        ]);
        foreach ([['redeem', -3, -1], ['earn', 10, 2]] as [$type, $points, $stamps]) {
            DB::table('pos_loyalty_transactions')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'loyalty_account_id' => $account,
                'order_id' => $this->order->id, 'type' => $type, 'points_delta' => $points, 'stamps_delta' => $stamps,
            ]);
        }
        DB::table('pos_roundup_donations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id,
            'order_id' => $this->order->id, 'payment_id' => $this->payment->id, 'amount' => '.125', 'status' => 'success',
        ]);
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id,
            'order_id' => $this->order->id, 'payment_id' => $this->payment->id, 'party_type' => 'platform',
            'party_label' => 'Platform', 'percent' => 1, 'gross_amount' => '8.375', 'commission_amount' => '.084',
        ]);
    }

    public static function stockModes(): array
    {
        return [['unit', 7, 1], ['ingredient', 5, 0], ['cooked', 5, 0], ['untracked', 5, 0]];
    }

    #[DataProvider('stockModes')]
    public function test_refund_stock_modes_are_frozen_and_only_unit_returns(string $mode, int $stock, int $movements): void
    {
        DB::table('pos_products')->where('id', 1)->update(['stock_mode' => $mode]);
        $payload = $this->payload();
        unset($payload['custom_amount_baisas']);
        $payload['lines'] = [['order_item_id' => $this->items[0]->id, 'qty' => 2]];
        $uuid = $this->reserve($payload)->assertOk()->json('data.reversal_uuid');
        DB::table('pos_products')->where('id', 1)->update(['stock_mode' => $mode === 'unit' ? 'cooked' : 'unit']);
        $this->submitResult($uuid)->assertOk();
        $this->assertDatabaseHas('pos_branch_product', ['product_id' => 1, 'stock_qty' => $stock]);
        $this->assertDatabaseCount('pos_product_stock_movements', $movements);
        $this->assertDatabaseHas('pos_payment_reversal_lines', ['stock_mode_at_refund' => $mode, 'returned_to_stock' => $movements === 1]);
    }

    public static function voidStockFlags(): array
    {
        return [[false, 7], [true, 5]];
    }

    #[DataProvider('voidStockFlags')]
    public function test_approved_void_preserves_all_core_side_effects(bool $keepStock, int $stock): void
    {
        $this->seedEffects();
        $this->reason->update(['affects_inventory' => $keepStock]);
        $uuid = $this->reserve($this->payload(['kind' => 'void', 'void_reason_id' => $this->reason->id]))->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $this->assertSame('void', $this->order->fresh()->status);
        $this->assertSame(2, OrderItem::where('order_id', $this->order->id)->where('status', 'void')->count());
        $this->assertDatabaseHas('pos_branch_product', ['product_id' => 1, 'stock_qty' => $stock]);
        $this->assertDatabaseHas('pos_loyalty_accounts', ['point_balance' => 0, 'stamp_count' => 0]);
        $this->assertSame([3, -10], DB::table('pos_loyalty_transactions')->where('type', 'adjust')->orderBy('id')->pluck('points_delta')->all());
        $this->assertDatabaseHas('pos_roundup_donations', ['status' => 'void']);
        $this->assertNull($this->payment->fresh()->roundup_amount);
        $this->assertNotNull($this->payment->fresh()->voided_at);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseHas('pos_payments', ['direction' => 'reversal', 'amount' => '-8.375', 'softpos_auth_code' => 'AUTH-REV']);
        $this->assertSame('success', $this->payment->fresh()->status);
    }

    public function test_partial_refund_keeps_loyalty_and_full_refund_claws_back_only_earn(): void
    {
        $this->seedEffects();
        $uuid = $this->reserve($this->payload())->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
        $this->assertDatabaseHas('pos_loyalty_accounts', ['point_balance' => 7, 'stamp_count' => 1]);
        $uuid = $this->reserve($this->payload(['custom_amount_baisas' => 7375]))->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $this->assertSame('refunded', $this->order->fresh()->status);
        $this->assertDatabaseCount('pos_loyalty_transactions', 3);
        $this->assertDatabaseHas('pos_loyalty_transactions', ['type' => 'adjust', 'points_delta' => -7, 'stamps_delta' => -1]);
        $this->assertDatabaseHas('pos_roundup_donations', ['status' => 'success']);
        $this->assertSame('0.125', $this->payment->fresh()->roundup_amount);
        $this->assertDatabaseCount('pos_sale_commissions', 1);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    public function test_void_handler_and_extracted_action_produce_identical_row_states(): void
    {
        $this->seedEffects();
        $tables = ['pos_orders', 'pos_order_items', 'pos_payments', 'pos_branch_product', 'pos_product_stock_movements',
            'pos_stock_movements', 'pos_loyalty_accounts', 'pos_loyalty_transactions', 'pos_roundup_donations', 'pos_sale_commissions'];
        $snapshot = static fn () => array_map(static fn ($table) => DB::table($table)->orderBy('id')->get()
            ->map(static function ($row) use ($table): array {
                $values = (array) $row;
                // New ledger UUIDs are generated independently in each execution.
                if (in_array($table, ['pos_product_stock_movements', 'pos_stock_movements', 'pos_loyalty_transactions'], true)) {
                    unset($values['uuid']);
                }

                return $values;
            })->all(), $tables);
        DB::beginTransaction();
        $viaHandler = app(VoidOrderHandler::class)->handle(new SyncEvent(['payload_json' => [
            'order_uuid' => $this->order->uuid, 'void_reason_id' => $this->reason->id, 'reason' => 'Same reason',
        ]]), $this->device);
        $expected = $snapshot();
        DB::rollBack();
        $viaCore = app(VoidOrderCoreAction::class)->handle($this->order->fresh(), $this->device, now(), 'Same reason', $this->reason);
        $this->assertSame($viaHandler, $viaCore);
        $this->assertSame($expected, $snapshot());
    }

    public function test_shift_z_tenders_net_the_approved_reversal(): void
    {
        $shift = Shift::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->device->id, 'staff_id' => $this->manager->id,
            'status' => 'open', 'opening_cash' => 0, 'opened_at' => now()->subHours(2), 'is_shared' => false,
        ]);
        $uuid = $this->reserve($this->payload(['custom_amount_baisas' => 1375]))->assertOk()->json('data.reversal_uuid');
        $this->submitResult($uuid)->assertOk();
        $result = app(CloseShiftHandler::class)->handle(new SyncEvent(['payload_json' => [
            'shift_uuid' => $shift->uuid, 'closing_cash_baisas' => 0, 'closed_at' => now()->toIso8601String(),
        ]]), $this->device);
        $card = collect($result['summary']['tenders'])->firstWhere('method', 'card');
        $this->assertSame(7000, $card['amount_baisas']);
        $this->assertSame(2, $card['count']);
        $this->assertSame('closed', $shift->fresh()->status);
    }

    public function test_largest_remainder_is_exact_and_large_products_do_not_overflow(): void
    {
        $this->assertSame([1 => 3334, 2 => 3333, 3 => 3333], ReversalMoney::allocate(10000, [3 => 1, 2 => 1, 1 => 1]));
        $this->assertSame([999999999997, 1], ReversalMoney::fraction(999999999998, 999999999998, 999999999999));
    }
}
