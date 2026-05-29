<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8.7 — GET /api/v1/device/orders/active.
 *
 * The branch's not-yet-terminal orders (open / held / kitchen), with line
 * items + add-ons, money as integer baisas. Scoped to the device's branch.
 */
class DeviceActiveOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_ord', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
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
            'status' => Order::STATUS_OPEN,
            'source' => 'main_pos',
            'subtotal' => '3.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '3.000',
            'opened_at' => now(),
        ], $overrides));
    }

    public function test_lists_active_orders_with_items_and_addons(): void
    {
        $this->device();
        $order = $this->order();
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => 1, 'product_name_snapshot' => 'Latte',
            'qty' => '2.000', 'unit_price_snapshot' => '1.500', 'line_discount' => '0.000',
            'line_total' => '3.000', 'status' => 'open',
        ]);
        OrderItemAddon::create([
            'order_item_id' => $item->id, 'add_on_id' => 1, 'add_on_name_snapshot' => 'Oat',
            'price_delta_snapshot' => '0.500',
        ]);

        $res = $this->withToken('mdev_ord')->getJson('/api/v1/device/orders/active')->assertOk();

        $res->assertJsonPath('meta.money_unit', 'baisas')->assertJsonPath('meta.count', 1);
        $o = $res->json('data.orders.0');
        $this->assertSame($order->uuid, $o['uuid']);
        $this->assertSame(3000, $o['grand_total_baisas']);
        $this->assertSame('Latte', $o['items'][0]['product_name']);
        $this->assertSame(1500, $o['items'][0]['unit_price_baisas']);
        $this->assertSame(500, $o['items'][0]['addons'][0]['price_delta_baisas']);
    }

    public function test_excludes_terminal_orders(): void
    {
        $this->device();
        $this->order(['status' => Order::STATUS_OPEN]);
        $this->order(['status' => Order::STATUS_PAID]);
        $this->order(['status' => Order::STATUS_VOID]);

        $res = $this->withToken('mdev_ord')->getJson('/api/v1/device/orders/active')->assertOk();
        $this->assertSame(1, $res->json('meta.count'));
        $this->assertSame('open', $res->json('data.orders.0.status'));
    }

    public function test_is_scoped_to_the_devices_branch(): void
    {
        $this->device(); // branch 10
        $this->order(['branch_id' => 10]);
        $this->order(['branch_id' => 11]); // same company, other branch

        $res = $this->withToken('mdev_ord')->getJson('/api/v1/device/orders/active')->assertOk();
        $this->assertSame(1, $res->json('meta.count'));
    }

    public function test_requires_a_device_token(): void
    {
        $this->getJson('/api/v1/device/orders/active')->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);
        $this->withToken('mdev_unassigned')->getJson('/api/v1/device/orders/active')->assertStatus(409);
    }
}
