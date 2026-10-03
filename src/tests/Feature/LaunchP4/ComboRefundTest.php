<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P4 A3 — a partial refund of a combo line returns the unit shelf of
 * the items chosen inside it in proportion. The combo line carries the
 * money; the refund derives one zero-money line per child with its own
 * stock mode, which the refund result returns like any refunded line (the
 * pos_admin reversal copy reads the same lines). Children are never
 * refundable on their own.
 */
final class ComboRefundTest extends TestCase
{
    use RefreshDatabase;

    private Payment $payment;

    private OrderItem $meal;

    private OrderItem $fries;

    private OrderItem $burger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'UTC'));
        $device = Device::factory()->withSoftPos()->paired('p4-combo-refund')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos', 'terminal_id' => 'T1',
        ]);
        $manager = PosStaff::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Manager', 'position' => 'manager', 'status' => 'active', 'pin_hash' => Hash::make('123456'),
        ]);
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $device->id,
            'staff_id' => $manager->id, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid',
            'subtotal' => 6, 'discount_total' => 0, 'comp_total' => 0, 'tax_total' => 0,
            'grand_total' => 6, 'opened_at' => now()->subHour(), 'closed_at' => now()->subMinute(),
        ]);
        foreach ([[1, 'Burger meal', 'untracked', 'combo'], [2, 'Fries', 'unit', 'standard'], [3, 'Burger', 'ingredient', 'standard']] as [$id, $name, $mode, $type]) {
            DB::table('pos_products')->insert(['id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => $name,
                'base_price' => 1, 'stock_mode' => $mode, 'product_type' => $type, 'status' => 'active']);
        }
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => 2, 'stock_qty' => 5]);
        $item = fn (int $productId, float $total, ?int $parent): OrderItem => OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productId, 'product_name_snapshot' => 'P'.$productId,
            'qty' => 2, 'unit_price_snapshot' => $total / 2, 'line_discount' => 0, 'line_total' => $total,
            'status' => 'open', 'component_snapshot_json' => [], 'parent_order_item_id' => $parent,
        ]);
        $this->meal = $item(1, 6, null);
        $this->fries = $item(2, 0, (int) $this->meal->id);
        $this->burger = $item(3, 0, (int) $this->meal->id);
        $this->payment = Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'device_id' => $device->id,
            'method' => 'card', 'status' => 'success', 'direction' => 'sale', 'amount' => '6.000',
            'bank_id' => $device->bank_id, 'terminal_id' => 'T1',
            'softpos_provider' => 'mosambee_dhofar', 'softpos_package' => 'com.mosambee.dhofar.softpos',
            'softpos_transaction_id' => 'ORIGINAL-1', 'softpos_auth_code' => 'AUTH-SALE', 'captured_at' => now()->subMinute(),
        ]);
        $this->withToken('p4-combo-refund');
    }

    /** @param list<array{order_item_id: int, qty: int}> $lines */
    private function reserve(array $lines): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/device/payments/'.$this->payment->uuid.'/reversals', [
            'kind' => 'refund', 'manager_pin' => '123456', 'client_request_id' => (string) Str::uuid(),
            'reason_code' => 'RETURN', 'lines' => $lines,
        ]);
    }

    public function test_refunding_one_of_two_meals_returns_one_shelf_item_of_each_child(): void
    {
        $uuid = $this->reserve([['order_item_id' => (int) $this->meal->id, 'qty' => 1]])->assertOk()
            ->assertJsonPath('data.amount_baisas', 3000)->json('data.reversal_uuid');
        $lines = DB::table('pos_payment_reversal_lines')->orderBy('order_item_id')->get();
        $this->assertSame(
            [[(int) $this->meal->id, 1.0, 3000, 'untracked'], [(int) $this->fries->id, 1.0, 0, 'unit'], [(int) $this->burger->id, 1.0, 0, 'ingredient']],
            $lines->map(fn ($l): array => [(int) $l->order_item_id, (float) $l->qty, (int) $l->amount_baisas, $l->stock_mode_at_refund])->all(),
        );

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/device/payments/reversals/'.$uuid.'/result', [
            'status' => 'approved', 'client_request_id' => (string) Str::uuid(), 'response_code' => '00',
            'auth_code' => 'AUTH-REV', 'reversal_transaction_id' => 'REV-TX', 'receipt_json' => ['status' => 'approved'],
        ])->assertOk();

        $this->assertSame(6.0, (float) DB::table('pos_branch_product')->where('product_id', 2)->value('stock_qty'));
        $this->assertDatabaseHas('pos_product_stock_movements', ['product_id' => 2, 'movement_type' => 'refund_return', 'quantity' => 1]);
        $this->assertSame('paid', Order::query()->value('status'));
    }

    public function test_a_combo_child_is_never_refunded_on_its_own(): void
    {
        $this->reserve([['order_item_id' => (int) $this->fries->id, 'qty' => 1]])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'refund_qty_exceeds_sold');
        $this->assertDatabaseCount('pos_payment_reversals', 0);
    }
}
