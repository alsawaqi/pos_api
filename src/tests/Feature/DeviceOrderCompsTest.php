<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceOrderCompsTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_comps', int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => $branch,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'order_type' => 'dine_in',
            'status' => Order::STATUS_PAID,
            'source' => 'main_pos',
            'subtotal' => '3.000',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '3.000',
            'opened_at' => now(),
        ], $overrides));
    }

    private function item(int $orderId, string $name): OrderItem
    {
        return OrderItem::create([
            'order_id' => $orderId,
            'product_id' => null,
            'product_name_snapshot' => $name,
            'qty' => '1.000',
            'unit_price_snapshot' => '1.000',
            'line_discount' => '0.000',
            'line_total' => '1.000',
            'status' => 'paid',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function comp(Order $order, array $overrides = []): OrderComp
    {
        return OrderComp::create(array_merge([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'order_item_id' => null,
            'comp_reason_id' => 1,
            'reason_code_snapshot' => 'quality',
            'reason_name_snapshot' => 'Quality Issue',
            'is_gift' => false,
            'amount' => '1.000',
            'qty' => null,
            'approved_by_pos_staff_id' => null,
            'note' => null,
            'applied_at' => now(),
        ], $overrides));
    }

    public function test_history_serves_reasoned_and_gift_comps_in_id_order(): void
    {
        $this->device();
        $order = $this->order(['comp_total' => '2.500']);
        $firstItem = $this->item($order->id, 'Coffee');
        $secondItem = $this->item($order->id, 'Cake');

        $reasoned = $this->comp($order, [
            'order_item_id' => $secondItem->id,
            'comp_reason_id' => 2,
            'reason_code_snapshot' => 'staff_meal',
            'reason_name_snapshot' => 'Staff Meal',
            'amount' => '1.500',
            'qty' => '1.000',
            'approved_by_pos_staff_id' => 9,
            'note' => 'regular',
        ]);
        $gift = $this->comp($order, [
            'order_item_id' => $firstItem->id,
            'comp_reason_id' => null,
            'reason_code_snapshot' => 'gift',
            'reason_name_snapshot' => 'Gift',
            'is_gift' => true,
            'amount' => '1.000',
        ]);

        $response = $this->withToken('mdev_comps')
            ->getJson('/api/v1/device/orders/history')
            ->assertOk();

        $this->assertSame(2500, $response->json('data.orders.0.comp_total_baisas'));
        $comps = $response->json('data.orders.0.comps');
        $this->assertCount(2, $comps);
        $this->assertSame([(int) $reasoned->id, (int) $gift->id], array_column($comps, 'id'));

        $this->assertSame((int) $secondItem->id, $comps[0]['order_item_id']);
        $this->assertSame(1, $comps[0]['line_index']);
        $this->assertSame(2, $comps[0]['comp_reason_id']);
        $this->assertSame('staff_meal', $comps[0]['reason_code']);
        $this->assertSame('Staff Meal', $comps[0]['reason_name']);
        $this->assertFalse($comps[0]['is_gift']);
        $this->assertSame(1500, $comps[0]['amount_baisas']);
        $this->assertSame(1, $comps[0]['qty']);
        $this->assertIsInt($comps[0]['qty']);
        $this->assertSame(9, $comps[0]['staff_id']);
        $this->assertSame('regular', $comps[0]['note']);

        $this->assertSame((int) $firstItem->id, $comps[1]['order_item_id']);
        $this->assertSame(0, $comps[1]['line_index']);
        $this->assertNull($comps[1]['comp_reason_id']);
        $this->assertTrue($comps[1]['is_gift']);
        $this->assertSame(1000, $comps[1]['amount_baisas']);
        $this->assertNull($comps[1]['qty']);
    }

    public function test_history_serves_a_null_line_index_for_a_whole_order_comp(): void
    {
        $this->device();
        $order = $this->order(['comp_total' => '1.000']);
        $this->comp($order, ['order_item_id' => null]);

        $response = $this->withToken('mdev_comps')
            ->getJson('/api/v1/device/orders/history')
            ->assertOk();

        $this->assertNull($response->json('data.orders.0.comps.0.order_item_id'));
        $this->assertNull($response->json('data.orders.0.comps.0.line_index'));
    }

    public function test_history_always_serves_empty_comp_defaults(): void
    {
        $this->device();
        $this->order();

        $response = $this->withToken('mdev_comps')
            ->getJson('/api/v1/device/orders/history')
            ->assertOk();

        $this->assertSame(0, $response->json('data.orders.0.comp_total_baisas'));
        $this->assertSame([], $response->json('data.orders.0.comps'));
    }

    public function test_active_held_order_always_serves_empty_comp_defaults(): void
    {
        $this->device();
        $this->order(['status' => Order::STATUS_HELD]);

        $response = $this->withToken('mdev_comps')
            ->getJson('/api/v1/device/orders/active')
            ->assertOk();

        $this->assertSame(0, $response->json('data.orders.0.comp_total_baisas'));
        $this->assertSame([], $response->json('data.orders.0.comps'));
    }

    public function test_comp_from_another_order_and_branch_is_never_attached(): void
    {
        $this->device();
        $this->order();
        $foreignOrder = $this->order([
            'branch_id' => 11,
            'comp_total' => '1.000',
        ]);
        $this->comp($foreignOrder);

        $response = $this->withToken('mdev_comps')
            ->getJson('/api/v1/device/orders/history')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data.orders'));
        $this->assertSame([], $response->json('data.orders.0.comps'));
    }
}
