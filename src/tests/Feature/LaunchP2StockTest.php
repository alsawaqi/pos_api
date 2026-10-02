<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P2 (device plane):
 *  - P2-1 a sale freezes the per-base-unit cost at 6 decimals and moves
 *    ingredient stock at 4 decimals (0.3 g of a kg ingredient survives);
 *  - P2-6 the ledger is dated at the SALE time (paid_at, or the delivery
 *    hand-off), a device count compares with the balance at the count
 *    moment, and a sale (or count) dated before a count that syncs after it
 *    is folded into that count. The count event payload is unchanged.
 */
class LaunchP2StockTest extends TestCase
{
    use RefreshDatabase;

    private const SAFFRON = 1;

    private const FLOUR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $this->seedCatalogue();
        Device::factory()->paired('mdev_p2')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedCatalogue(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        // LAUNCH-P3 P3-7: a recipe product is made-to-order; an untracked
        // product never deducts a leftover recipe.
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Saffron bun', 'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => self::SAFFRON, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Saffron', 'unit' => 'kg', 'default_unit_cost' => '1200.000', 'status' => 'active'] + $t,
            ['id' => self::FLOUR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour', 'unit' => 'g', 'default_unit_cost' => '0.00035', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => self::SAFFRON, 'quantity' => '0.0003', 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
            ['product_id' => 1, 'ingredient_id' => self::FLOUR, 'quantity' => '80', 'unit_at_set' => 'g', 'sort_order' => 2] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::SAFFRON, 'quantity' => '0.010'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::FLOUR, 'quantity' => '5000'] + $t,
        ]);
        DB::table('pos_delivery_providers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat', 'commission_percent' => 20.00, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createEvent(string $uuid, Carbon $openedAt, string $orderType = 'dine_in'): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => $openedAt->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $uuid, 'order_type' => $orderType, 'source' => 'main_pos', 'staff_id' => 7,
                'opened_at' => $openedAt->toIso8601String(),
                'subtotal_baisas' => 3000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 3000,
                'lines' => [['product_id' => 1, 'qty' => 2, 'unit_price_baisas' => 1500, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payEvent(string $uuid, Carbon $paidAt): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => $paidAt->toIso8601String(),
            'payload' => [
                'order_uuid' => $uuid, 'paid_at' => $paidAt->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]],
            ],
        ];
    }

    /**
     * The device count event exactly as shipped APKs send it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function countEvent(Carbon $countedAt, array $lines): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'stock.count',
            'client_timestamp' => $countedAt->toIso8601String(),
            'payload' => ['lines' => $lines, 'staff_id' => 7, 'counted_at' => $countedAt->toIso8601String()],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events, string $token = 'mdev_p2'): TestResponse
    {
        $response = $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
        foreach ($response->json('data.results') as $result) {
            $this->assertSame('processed', $result['status'], json_encode($result));
        }

        return $response;
    }

    private function sell(Carbon $paidAt): string
    {
        $uuid = (string) Str::uuid();
        $this->push([$this->createEvent($uuid, $paidAt->copy()->subMinutes(10)), $this->payEvent($uuid, $paidAt)]);

        return $uuid;
    }

    private function balance(int $ingredientId): float
    {
        return (float) DB::table('pos_branch_stock')->where('branch_id', 10)->where('ingredient_id', $ingredientId)->value('quantity');
    }

    public function test_a_sale_freezes_six_decimal_costs_and_moves_four_decimal_quantities_at_the_sale_time(): void
    {
        $paidAt = now()->subHour()->startOfSecond();
        $this->sell($paidAt);

        $item = OrderItem::query()->sole();
        $snapshot = collect($item->recipe_snapshot_json)->keyBy('ingredient_id');
        $this->assertEqualsWithDelta(0.0003, $snapshot[self::SAFFRON]['qty'], 1e-12);
        $this->assertEqualsWithDelta(0.00035, $snapshot[self::FLOUR]['unit_cost'], 1e-12);

        $saffron = DB::table('pos_stock_movements')->where('ingredient_id', self::SAFFRON)->sole();
        $flour = DB::table('pos_stock_movements')->where('ingredient_id', self::FLOUR)->sole();
        $this->assertSame(-0.0006, (float) $saffron->quantity);
        $this->assertSame(1200.0, (float) $saffron->unit_cost_at_time);
        $this->assertSame(-160.0, (float) $flour->quantity);
        $this->assertSame(0.00035, (float) $flour->unit_cost_at_time);
        $this->assertSame($paidAt->toDateTimeString(), Carbon::parse($flour->occurred_at)->toDateTimeString());
        $this->assertSame(0.0094, $this->balance(self::SAFFRON));
    }

    public function test_a_delivery_hand_off_is_dated_at_the_device_delivered_at(): void
    {
        $uuid = (string) Str::uuid();
        $deliveredAt = now()->subHours(3)->startOfSecond();
        $this->push([
            $this->createEvent($uuid, $deliveredAt->copy()->subMinutes(10), 'delivery'),
            [
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.deliver',
                'client_timestamp' => $deliveredAt->toIso8601String(),
                'payload' => ['order_uuid' => $uuid, 'delivered_at' => $deliveredAt->toIso8601String(),
                    'delivery' => ['provider_id' => 1, 'reference' => 'TLB-1', 'customer_phone' => '91234567', 'driver_phone' => '99887766']],
            ],
        ]);

        $movement = DB::table('pos_stock_movements')->where('ingredient_id', self::FLOUR)->sole();
        $this->assertSame($deliveredAt->toDateTimeString(), Carbon::parse($movement->occurred_at)->toDateTimeString());
    }

    public function test_a_device_count_compares_with_the_balance_at_the_count_moment(): void
    {
        // Sold at 13:00 (synced first), counted at 12:00 (synced after).
        $this->sell(now()->subHour());
        $this->assertSame(4840.0, $this->balance(self::FLOUR));

        $this->push([$this->countEvent(now()->subHours(2), [['ingredient_id' => self::FLOUR, 'counted_units' => 5000]])]);

        $line = DB::table('pos_stock_count_lines')->sole();
        $this->assertSame(5000.0, (float) $line->expected_units);
        $this->assertSame(0.0, (float) $line->variance_units);
        // What was counted at 12:00, then the 13:00 sale.
        $this->assertSame(4840.0, $this->balance(self::FLOUR));
    }

    public function test_a_till_count_without_counted_at_is_taken_at_the_event_time_and_answers_as_before(): void
    {
        $this->sell(now()->subHour());

        // The till's shape: {lines, staff_id}; the moment is client_timestamp.
        $countedAt = now()->subHours(2)->startOfSecond();
        $response = $this->push([[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'stock.count',
            'client_timestamp' => $countedAt->toIso8601String(),
            'payload' => ['lines' => [['ingredient_id' => self::FLOUR, 'counted_units' => 4990]], 'staff_id' => 7],
        ]]);

        $this->assertSame($countedAt->toDateTimeString(), Carbon::parse(DB::table('pos_stock_counts')->value('counted_at'))->toDateTimeString());
        $line = DB::table('pos_stock_count_lines')->sole();
        $this->assertSame(5000.0, (float) $line->expected_units);
        $this->assertSame(-10.0, (float) $line->variance_units);
        // The device answer keeps its old shape (old APKs read it).
        $this->assertSame(['stock_count_id', 'lines', 'lines_with_variance'], array_keys($response->json('data.results.0.result')));
        $this->assertSame(1, $response->json('data.results.0.result.lines_with_variance'));
    }

    public function test_a_sale_made_before_a_count_but_synced_after_it_is_folded_into_that_count(): void
    {
        $countedAt = now()->subHour()->startOfSecond();
        // The count at 13:00 finds 4800 g: 200 g short of the books (5000).
        $this->push([$this->countEvent($countedAt, [['ingredient_id' => self::FLOUR, 'counted_units' => 4800]])]);
        $this->assertSame(4800.0, $this->balance(self::FLOUR));

        // An offline till sold 160 g at 12:00 and syncs now.
        $order = $this->sell(now()->subHours(2));

        $this->assertSame(4800.0, $this->balance(self::FLOUR));
        $line = DB::table('pos_stock_count_lines')->sole();
        $this->assertSame(4840.0, (float) $line->expected_units);
        $this->assertSame(-40.0, (float) $line->variance_units);
        $this->assertSame(-160.0, (float) $line->late_movement_units);
        $correction = DB::table('pos_stock_movements')->where('movement_type', 'count_correction')->sole();
        $this->assertSame(160.0, (float) $correction->quantity);
        $this->assertSame($countedAt->toDateTimeString(), Carbon::parse($correction->occurred_at)->toDateTimeString());
        $this->assertSame((int) $line->id, (int) $correction->reference_id);
        $waste = DB::table('pos_waste_records')->where('reason', 'reconciliation_variance')->sole();
        $this->assertSame(40.0, (float) $waste->quantity);
        $this->assertSame((int) $waste->id, (int) $line->waste_record_id);

        // Voiding that sale now is a NEW event at the void time (after the
        // count): the ingredients come back after the count, which stays as
        // it was observed.
        $this->push([[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order, 'voided_at' => now()->toIso8601String(), 'reason' => 'customer left'],
        ]]);
        $this->assertSame(4960.0, $this->balance(self::FLOUR));
        $this->assertSame(1, DB::table('pos_stock_movements')->where('movement_type', 'count_correction')->count());
        $this->assertSame(-40.0, (float) DB::table('pos_stock_count_lines')->sole()->variance_units);
    }

    public function test_a_count_taken_earlier_but_synced_later_is_folded_into_the_later_count(): void
    {
        Device::factory()->paired('mdev_p2_b')->create(['company_id' => 100, 'branch_id' => 10]);

        // Device A counts at 13:00: 4900 g.
        $this->push([$this->countEvent(now()->subHour(), [['ingredient_id' => self::FLOUR, 'counted_units' => 4900]])]);
        // Device B counted 4950 g at 12:00 but syncs only now.
        $this->push([$this->countEvent(now()->subHours(2), [['ingredient_id' => self::FLOUR, 'counted_units' => 4950]])], 'mdev_p2_b');

        // The latest observation stands: 4900 g.
        $this->assertSame(4900.0, $this->balance(self::FLOUR));
        $lines = DB::table('pos_stock_count_lines')
            ->join('pos_stock_counts', 'pos_stock_counts.id', '=', 'pos_stock_count_lines.stock_count_id')
            ->orderBy('pos_stock_counts.counted_at')
            ->get(['pos_stock_count_lines.*']);
        // 12:00 count: 5000 on the books, 50 short.
        $this->assertSame(-50.0, (float) $lines[0]->variance_units);
        // 13:00 count: now measured against the 12:00 count's 4950.
        $this->assertSame(4950.0, (float) $lines[1]->expected_units);
        $this->assertSame(-50.0, (float) $lines[1]->variance_units);
        $this->assertSame(100.0, (float) DB::table('pos_waste_records')->where('reason', 'reconciliation_variance')->sum('quantity'));
    }
}
