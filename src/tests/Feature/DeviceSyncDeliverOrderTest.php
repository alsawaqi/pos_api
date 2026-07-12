<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Order;
use App\Models\SaleCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P-G7 — `order.deliver`: closing a delivery-provider order with NO tender.
 *
 * The order lands as pending_verification with the provider snapshot +
 * expected payout (grand − commission%) frozen at punch. Inventory consumes
 * at intake (the food left the shop); the commission split and revenue
 * recognition wait for the merchant's Deliveries-page confirmation.
 * Catalogue: company 100 / branch 10, Latte (recipe 0.25 L Milk), provider
 * Talabat (id 1, 20%) + a foreign-tenant provider (id 2).
 */
class DeviceSyncDeliverOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the delivered order's cashier (staff 7) is in the tenant.
        $this->seedPosStaff([7]);
    }

    private function device(string $token = 'mdev_dlv', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedCatalogue(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'l', 'default_unit_cost' => 0.400, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => 1, 'ingredient_id' => 1, 'quantity' => 0.250, 'unit_at_set' => 'l', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => 5.000] + $t,
        ]);
        DB::table('pos_delivery_providers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat', 'commission_percent' => 20.00, 'is_active' => true, 'sort_order' => 1] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 999, 'name' => 'Foreign Eats', 'commission_percent' => 30.00, 'is_active' => true, 'sort_order' => 1] + $t,
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid, array $orderOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHours(4)->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $orderUuid,
                'order_type' => 'delivery',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->subHours(4)->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 2,
                    'unit_price_baisas' => 1500,
                    'line_discount_baisas' => 0,
                    'line_total_baisas' => 3000,
                ]],
            ], $orderOverrides)],
        ];
    }

    /**
     * @param  array<string, mixed>  $deliveryOverrides
     * @return array<string, mixed>
     */
    private function deliverEvent(string $orderUuid, array $deliveryOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.deliver',
            'client_timestamp' => now()->subHours(3)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'delivered_at' => now()->subHours(3)->toIso8601String(),
                'delivery' => array_merge([
                    'provider_id' => 1,
                    'reference' => 'TLB-88421',
                    'customer_phone' => '91234567',
                    'driver_phone' => '99887766',
                ], $deliveryOverrides),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_deliver_lands_pending_verification_with_snapshot_and_consumes_stock(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_dlv', [$this->createEvent($uuid), $this->deliverEvent($uuid)]);
        $res->assertOk();
        $this->assertSame('processed', $res->json('data.results.1.status'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_VERIFICATION, $order->status);
        $this->assertSame(1, (int) $order->delivery_provider_id);
        $this->assertSame('Talabat', $order->delivery_provider_name);
        $this->assertSame('TLB-88421', $order->delivery_reference);
        $this->assertSame('91234567', $order->delivery_customer_phone);
        $this->assertSame('99887766', $order->delivery_driver_phone);
        // 3.000 OMR grand, 20% commission → expected payout 2.400.
        $this->assertSame('20.00', (string) $order->delivery_commission_percent);
        $this->assertSame('2.400', (string) $order->delivery_expected_payout);
        $this->assertNotNull($order->delivery_punched_at);
        // NOT revenue yet: closed_at (the revenue stamp) stays empty.
        $this->assertNull($order->closed_at);

        // Inventory consumed at intake: 2 lattes × 0.25 L milk = 0.5 L.
        $milk = BranchStock::query()->where('branch_id', 10)->where('ingredient_id', 1)->firstOrFail();
        $this->assertSame('4.500', (string) $milk->quantity);

        // The expected payout rides the ACK for the device's own books.
        $this->assertSame(2400, $res->json('data.results.1.result.expected_payout_baisas'));

        // No commission breakdown until confirmation (the P-F7 deferral rule).
        $this->assertSame(0, SaleCommission::query()->count());
    }

    public function test_deliver_requires_a_reference_and_a_tenant_provider(): void
    {
        $this->seedCatalogue();
        $this->device();

        // Missing reference → the event fails (the order stays open).
        $uuid = (string) Str::uuid();
        $res = $this->push('mdev_dlv', [
            $this->createEvent($uuid),
            $this->deliverEvent($uuid, ['reference' => '  ']),
        ]);
        $this->assertSame('failed', $res->json('data.results.1.status'));
        $this->assertSame(Order::STATUS_OPEN, Order::query()->where('uuid', $uuid)->value('status'));

        // A foreign tenant's provider id → fails, no cross-tenant linkage.
        $uuid2 = (string) Str::uuid();
        $res2 = $this->push('mdev_dlv', [
            $this->createEvent($uuid2),
            $this->deliverEvent($uuid2, ['provider_id' => 2]),
        ]);
        $this->assertSame('failed', $res2->json('data.results.1.status'));

        // A non-delivery order can't ride order.deliver.
        $uuid3 = (string) Str::uuid();
        $res3 = $this->push('mdev_dlv', [
            $this->createEvent($uuid3, ['order_type' => 'dine_in']),
            $this->deliverEvent($uuid3),
        ]);
        $this->assertSame('failed', $res3->json('data.results.1.status'));
    }

    public function test_pending_delivery_blocks_pay_and_recreate_but_voids_with_inventory_reversal(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->push('mdev_dlv', [$this->createEvent($uuid), $this->deliverEvent($uuid)])->assertOk();

        // order.pay on a pending delivery → fails (settles via the portal).
        $pay = $this->push('mdev_dlv', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $uuid,
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
            ],
        ]]);
        $this->assertSame('failed', $pay->json('data.results.0.status'));

        // A replayed order.create for the same uuid can't wipe the snapshot.
        $recreate = $this->push('mdev_dlv', [$this->createEvent($uuid)]);
        $this->assertSame('failed', $recreate->json('data.results.0.status'));

        // Manager void unwinds the intake consumption (milk back to 5.000).
        $void = $this->push('mdev_dlv', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $uuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'provider cancelled'],
        ]]);
        $this->assertSame('processed', $void->json('data.results.0.status'));
        $this->assertSame(Order::STATUS_VOID, Order::query()->where('uuid', $uuid)->value('status'));
        $milk = BranchStock::query()->where('branch_id', 10)->where('ingredient_id', 1)->firstOrFail();
        $this->assertSame('5.000', (string) $milk->quantity);
    }

    public function test_pending_deliveries_surface_in_device_history_with_the_provider_block(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->push('mdev_dlv', [$this->createEvent($uuid), $this->deliverEvent($uuid)])->assertOk();

        $history = $this->withToken('mdev_dlv')->getJson('/api/v1/device/orders/history')->assertOk();
        $row = collect($history->json('data.orders'))->firstWhere('uuid', $uuid);
        $this->assertNotNull($row);
        $this->assertSame(Order::STATUS_PENDING_VERIFICATION, $row['status']);
        $this->assertSame('Talabat', $row['delivery']['provider_name']);
        $this->assertSame('TLB-88421', $row['delivery']['reference']);

        // And it is NOT resumable: absent from the active list.
        $active = $this->withToken('mdev_dlv')->getJson('/api/v1/device/orders/active')->assertOk();
        $this->assertNull(collect($active->json('data.orders'))->firstWhere('uuid', $uuid));
    }
}
