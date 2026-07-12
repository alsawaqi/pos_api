<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.10 — discount-application records written at order.create.
 *
 * Pricing is snapshot-authoritative: the device sends the discounts it applied
 * and the handler persists one pos_order_discounts row each — resolving a known
 * rule to its catalogue name/type, tying a line_index to that line's item, and
 * recording manual (rule-less) discounts as sent. This is the data path behind
 * the merchant by-rule Discount Report.
 */
class DeviceOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        // order.create references product 1; it must belong to the device's
        // company or the tenant guard (correctly) rejects the order.
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Item', 'base_price' => 3.000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Device::factory()->paired('mdev_disc')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedRule(): void
    {
        DB::table('pos_discounts')->insert([
            'id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Weekday Lunch', 'scope' => 'order', 'amount_type' => 'percent',
            'amount' => 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @return array<string, mixed>
     */
    private function createEvent(string $uuid, array $discounts): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $uuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 800,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 2200,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 500, 'line_total_baisas' => 2500]],
                'discounts' => $discounts,
            ]],
        ];
    }

    private function push(array $event): TestResponse
    {
        return $this->withToken('mdev_disc')->postJson('/api/v1/device/sync/push', ['events' => [$event]]);
    }

    public function test_order_create_persists_rule_and_manual_discount_applications(): void
    {
        $this->device();
        $this->seedRule();
        $uuid = (string) Str::uuid();

        $event = $this->createEvent($uuid, [
            // Known rule, line-level — the catalogue name/type wins over the sent label.
            // No reason sent → NULL persists.
            ['discount_id' => 7, 'name' => 'Lunch (sent label)', 'amount_type' => 'percent', 'amount_baisas' => 500, 'line_index' => 0],
            // Manual / ad-hoc, order-level — no rule behind it. P-F4: carries
            // the cashier's free-text reason (persisted trimmed).
            ['name' => 'Manager comp', 'amount_baisas' => 300, 'reason' => '  Regular customer  '],
        ]);

        $res = $this->push($event)->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(2, $res->json('data.results.0.result.discounts'));

        $order = Order::firstWhere('uuid', $uuid);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $rows = OrderDiscount::where('order_id', $order->id)->get();
        $this->assertCount(2, $rows);

        $rule = $rows->firstWhere('discount_id', 7);
        $this->assertNotNull($rule);
        $this->assertSame('Weekday Lunch', $rule->name_snapshot);     // catalogue, not the sent label
        $this->assertSame('percent', $rule->amount_type_snapshot);
        $this->assertSame('0.500', $rule->amount);
        $this->assertEquals($item->id, $rule->order_item_id);          // line-level
        $this->assertNull($rule->reason);                              // reason absent → NULL

        $manual = $rows->firstWhere('name_snapshot', 'Manager comp');
        $this->assertNotNull($manual);
        $this->assertNull($manual->discount_id);
        $this->assertNull($manual->order_item_id);                     // order-level
        $this->assertNull($manual->amount_type_snapshot);
        $this->assertSame('0.300', $manual->amount);
        // P-F4: the cashier's free-text reason persists trimmed.
        $this->assertSame('Regular customer', $manual->reason);
    }

    public function test_a_long_reason_is_capped_to_160_chars_instead_of_rejecting_the_order(): void
    {
        // The whole offline batch must not fail over a long note — the
        // handler truncates to the column's 160 chars.
        $this->device();
        $uuid = (string) Str::uuid();

        $res = $this->push($this->createEvent($uuid, [
            ['name' => 'Manual', 'amount_baisas' => 300, 'reason' => str_repeat('x', 200)],
        ]))->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $row = OrderDiscount::firstOrFail();
        $this->assertSame(str_repeat('x', 160), $row->reason);
    }

    public function test_an_unresolved_discount_id_is_dropped_to_null_not_persisted_raw(): void
    {
        // Phase 4 — a discount_id that does not resolve to one of the device
        // company's rules must NOT be written verbatim (an unvalidated foreign
        // FK on pos_order_discounts): it is dropped to null while the
        // device-sent label still stands. A real rule (id 5) exists but in
        // ANOTHER company, so it does not resolve for this device.
        $this->device();
        DB::table('pos_discounts')->insert([
            'id' => 5, 'uuid' => (string) Str::uuid(), 'company_id' => 200,
            'name' => 'Foreign Rule', 'scope' => 'order', 'amount_type' => 'percent',
            'amount' => 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uuid = (string) Str::uuid();

        $res = $this->push($this->createEvent($uuid, [
            ['discount_id' => 5, 'name' => 'Spoofed label', 'amount_baisas' => 800],
        ]))->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $row = OrderDiscount::firstOrFail();
        $this->assertNull($row->discount_id);                    // foreign FK dropped, not raw
        $this->assertSame('Spoofed label', $row->name_snapshot); // device label still stands
    }

    public function test_an_order_without_discounts_writes_no_application_rows(): void
    {
        $this->device();
        $uuid = (string) Str::uuid();

        $res = $this->push($this->createEvent($uuid, []))->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(0, $res->json('data.results.0.result.discounts'));
        $this->assertSame(0, OrderDiscount::count());
    }

    public function test_replaying_the_event_does_not_duplicate_discount_rows(): void
    {
        $this->device();
        $this->seedRule();
        $event = $this->createEvent((string) Str::uuid(), [
            ['discount_id' => 7, 'name' => 'x', 'amount_baisas' => 500, 'line_index' => 0],
        ]);

        // Same client_event_id pushed twice — settles once, handler not re-run.
        $this->push($event)->assertOk();
        $this->push($event)->assertOk();

        $this->assertSame(1, OrderDiscount::count());
    }
}
