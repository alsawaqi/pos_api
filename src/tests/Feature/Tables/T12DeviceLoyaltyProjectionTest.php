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
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class T12DeviceLoyaltyProjectionTest extends TestCase
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

    public function test_customer_search_and_show_project_available_units_without_writes(): void
    {
        [$device, $seat, $order] = $this->fixture();
        [$rule, $account, $customer] = $this->loyalty($order);
        $this->adjust($device, $seat, $this->redeem($rule, 2))->assertOk();
        $before = DB::table('pos_order_discounts')->get()->toJson();
        foreach (['customers/search?q=T12', 'customers/'.$customer->id] as $path) {
            $prefix = str_contains($path, 'search') ? 'data.customers.0.loyalty.0' : 'data.customer.loyalty.0';
            $this->withToken($device->plainTextToken)->getJson('/api/v1/device/'.$path)->assertOk()
                ->assertJsonPath($prefix.'.points', 2000)->assertJsonPath($prefix.'.stamps', 100)
                ->assertJsonPath($prefix.'.available_points', 1800)->assertJsonPath($prefix.'.available_stamps', 100);
        }
        $this->assertSame($before, DB::table('pos_order_discounts')->get()->toJson());
        $this->assertSame(2000, $account->fresh()->point_balance);
        $this->adjust($device, $seat, ['kind' => 'loyalty', 'mode' => 'clear'])->assertOk();
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/customers/'.$customer->id)->assertOk()
            ->assertJsonPath('data.customer.loyalty.0.available_points', 2000);
    }

    public function test_stamps_are_available_only_after_pending_slot_is_released(): void
    {
        [$device, $seat, $order] = $this->fixture();
        [$rule, $account, $customer] = $this->loyalty($order, 'visit_based');
        $this->adjust($device, $seat, $this->redeem($rule, 2))->assertOk();
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/customers/'.$customer->id)->assertOk()
            ->assertJsonPath('data.customer.loyalty.0.available_stamps', 90)
            ->assertJsonPath('data.customer.loyalty.0.available_points', 2000);
        $this->pay($device, $order);
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/customers/'.$customer->id)->assertOk()
            ->assertJsonPath('data.customer.loyalty.0.available_stamps', 91);
        $this->assertSame(91, $account->fresh()->stamp_count);
    }

    public function test_order_pay_result_reports_own_earn_and_replay_once(): void
    {
        [$device, $seat, $order] = $this->fixture();
        [$rule] = $this->loyalty($order);
        $this->adjust($device, $seat, $this->redeem($rule, 2))->assertOk();
        $id = (string) Str::uuid();
        $result = $this->pay($device, $order, $id)->assertJsonPath('data.results.0.result.loyalty_earned', ['points' => 34, 'stamps' => 0]);
        $before = LoyaltyTransaction::count();
        $this->pay($device, $order, $id)->assertJsonPath('data.results.0.result.loyalty_earned', $result->json('data.results.0.result.loyalty_earned'));
        $this->assertSame($before, LoyaltyTransaction::count());
    }

    public function test_anonymous_payment_reports_zero_earn(): void
    {
        [$device, , $order] = $this->fixture();
        $this->pay($device, $order)->assertJsonPath('data.results.0.result.loyalty_earned', ['points' => 0, 'stamps' => 0]);
    }
}
