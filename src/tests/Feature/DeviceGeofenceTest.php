<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.9 — geofence enforcement on order.create (blueprint §9.4).
 *
 * Branch 10 sits at (23.5880, 58.3829) with a 300 m fence (+100 m tolerance →
 * 400 m limit). An order.create event carries the device's GPS; the server
 * rejects it if that point is beyond the limit.
 */
class DeviceGeofenceTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_geo'): Device
    {
        // order.create references product 1; it must belong to the device's
        // company or the tenant guard (correctly) rejects the order.
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Item', 'base_price' => 3.000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedBranch(array $overrides = []): void
    {
        DB::table('pos_branches')->insert(array_merge([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main',
            'latitude' => 23.5880000, 'longitude' => 58.3829000, 'geofence_radius_m' => 300,
            'default_order_type' => 'dine_in', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array{lat: float, lng: float}|null  $gps
     * @return array<string, mixed>
     */
    private function createEvent(string $uuid, ?array $gps): array
    {
        $order = [
            'uuid' => $uuid,
            'order_type' => 'quick',
            'source' => 'main_pos',
            'opened_at' => now()->toIso8601String(),
            'subtotal_baisas' => 3000,
            'discount_total_baisas' => 0,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 3000,
            'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
        ];
        if ($gps !== null) {
            $order['gps'] = $gps;
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => $order],
        ];
    }

    private function push(string $uuid, ?array $gps): TestResponse
    {
        return $this->withToken('mdev_geo')->postJson('/api/v1/device/sync/push', ['events' => [$this->createEvent($uuid, $gps)]]);
    }

    public function test_an_order_inside_the_geofence_is_accepted(): void
    {
        $this->device();
        $this->seedBranch();
        $uuid = (string) Str::uuid();

        $res = $this->push($uuid, ['lat' => 23.5880, 'lng' => 58.3829])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertNotNull(Order::firstWhere('uuid', $uuid));
    }

    public function test_an_order_outside_the_geofence_is_rejected(): void
    {
        $this->device();
        $this->seedBranch();
        $uuid = (string) Str::uuid();

        // ~2 km away — well beyond the 400 m limit.
        $res = $this->push($uuid, ['lat' => 23.6050, 'lng' => 58.4000])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('geofence', $res->json('data.results.0.result.error'));
        $this->assertNull(Order::firstWhere('uuid', $uuid)); // not created
    }

    public function test_an_order_without_gps_skips_enforcement(): void
    {
        $this->device();
        $this->seedBranch();
        $uuid = (string) Str::uuid();

        $res = $this->push($uuid, null)->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertNotNull(Order::firstWhere('uuid', $uuid));
    }

    public function test_a_branch_without_coordinates_skips_enforcement(): void
    {
        $this->device();
        $this->seedBranch(['latitude' => null, 'longitude' => null]);
        $uuid = (string) Str::uuid();

        // GPS supplied but the branch has no fence configured → accepted.
        $res = $this->push($uuid, ['lat' => 0.0, 'lng' => 0.0])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertNotNull(Order::firstWhere('uuid', $uuid));
    }

    public function test_an_order_persists_the_device_gps(): void
    {
        $this->device();
        $this->seedBranch();
        $uuid = (string) Str::uuid();

        $this->push($uuid, ['lat' => 23.5880, 'lng' => 58.3829])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertNotNull($order);
        $this->assertSame('23.5880000', (string) $order->latitude);
        $this->assertSame('58.3829000', (string) $order->longitude);
    }

    public function test_an_order_without_gps_has_null_coordinates(): void
    {
        $this->device();
        $this->seedBranch();
        $uuid = (string) Str::uuid();

        $this->push($uuid, null)->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertNotNull($order);
        $this->assertNull($order->latitude);
        $this->assertNull($order->longitude);
    }
}
