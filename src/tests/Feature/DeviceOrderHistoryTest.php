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
 * GET /api/v1/device/orders/history — the branch's terminal (paid/void/
 * refunded) orders, so any device at the branch sees prior completed sales
 * (not just the local store on the device that rang them). Paginated +
 * date-filterable, money as integer baisas, branch-scoped.
 */
class DeviceOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_hist', int $company = 100, int $branch = 10): Device
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
            'status' => Order::STATUS_PAID,
            'source' => 'main_pos',
            'subtotal' => '3.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '3.000',
            'opened_at' => now(),
        ], $overrides));
    }

    public function test_lists_terminal_orders_with_items_and_addons(): void
    {
        $this->device();
        $order = $this->order();
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => 1, 'product_name_snapshot' => 'Latte',
            'qty' => '2.000', 'unit_price_snapshot' => '1.500', 'line_discount' => '0.000',
            'line_total' => '3.000', 'status' => 'paid',
        ]);
        OrderItemAddon::create([
            'order_item_id' => $item->id, 'add_on_id' => 1, 'add_on_name_snapshot' => 'Oat',
            'price_delta_snapshot' => '0.500',
        ]);

        $res = $this->withToken('mdev_hist')->getJson('/api/v1/device/orders/history')->assertOk();

        $res->assertJsonPath('meta.money_unit', 'baisas')->assertJsonPath('meta.total', 1);
        $o = $res->json('data.orders.0');
        $this->assertSame($order->uuid, $o['uuid']);
        $this->assertSame(3000, $o['grand_total_baisas']);
        $this->assertSame('Latte', $o['items'][0]['product_name']);
        $this->assertSame(500, $o['items'][0]['addons'][0]['price_delta_baisas']);
    }

    public function test_excludes_active_orders(): void
    {
        $this->device();
        $this->order(['status' => Order::STATUS_PAID]);
        $this->order(['status' => Order::STATUS_VOID]);
        $this->order(['status' => Order::STATUS_REFUNDED]);
        $this->order(['status' => Order::STATUS_OPEN]);   // active — excluded
        $this->order(['status' => Order::STATUS_HELD]);   // active — excluded

        $res = $this->withToken('mdev_hist')->getJson('/api/v1/device/orders/history')->assertOk();
        $this->assertSame(3, $res->json('meta.total'));
        foreach ($res->json('data.orders') as $o) {
            $this->assertContains($o['status'], ['paid', 'void', 'refunded']);
        }
    }

    public function test_is_scoped_to_the_devices_branch(): void
    {
        $this->device(); // branch 10
        $this->order(['branch_id' => 10]);
        $this->order(['branch_id' => 11]); // same company, other branch — excluded

        $res = $this->withToken('mdev_hist')->getJson('/api/v1/device/orders/history')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_orders_newest_first_and_paginates(): void
    {
        $this->device();
        $this->order(['opened_at' => now()->subDays(2)]);
        $this->order(['opened_at' => now()->subDay()]);
        $this->order(['opened_at' => now()]);

        $res = $this->withToken('mdev_hist')
            ->getJson('/api/v1/device/orders/history?per_page=2&page=1')->assertOk();

        $this->assertSame(3, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.last_page'));
        $this->assertCount(2, $res->json('data.orders'));
        // Newest first.
        $newest = $res->json('data.orders.0.opened_at');
        $second = $res->json('data.orders.1.opened_at');
        $this->assertGreaterThanOrEqual($second, $newest);
    }

    public function test_filters_by_date_window(): void
    {
        $this->device();
        $this->order(['opened_at' => now()->subDays(10)]); // outside
        $this->order(['opened_at' => now()->subDay()]);    // inside

        $from = now()->subDays(3)->toIso8601String();
        $res = $this->withToken('mdev_hist')
            ->getJson('/api/v1/device/orders/history?from='.urlencode($from))->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_requires_a_device_token(): void
    {
        $this->getJson('/api/v1/device/orders/history')->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned_hist')->create(['company_id' => null, 'branch_id' => null]);
        $this->withToken('mdev_unassigned_hist')->getJson('/api/v1/device/orders/history')->assertStatus(409);
    }
}
