<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8.0 — the pos_device guard, exercised via the heartbeat
 * endpoint (POST /api/v1/device/heartbeat).
 */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_requires_a_device_token(): void
    {
        $this->postJson('/api/v1/device/heartbeat')->assertStatus(401);
    }

    public function test_heartbeat_rejects_an_invalid_token(): void
    {
        Device::factory()->paired('mdev_realtoken')->create();

        $this->withToken('mdev_bogus')
            ->postJson('/api/v1/device/heartbeat')
            ->assertStatus(401);
    }

    public function test_heartbeat_records_telemetry_for_a_paired_device(): void
    {
        $device = Device::factory()->paired('mdev_livetoken')->create();

        $this->withToken('mdev_livetoken')
            ->postJson('/api/v1/device/heartbeat', [
                'lat' => 23.5880000,
                'lng' => 58.3829000,
                'battery' => 87,
                'app_version' => '1.0.0',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $device->refresh();
        $this->assertSame(87, (int) $device->last_battery);
        $this->assertSame('1.0.0', $device->app_version);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_heartbeat_validates_gps_ranges(): void
    {
        Device::factory()->paired('mdev_validtoken')->create();

        $this->withToken('mdev_validtoken')
            ->postJson('/api/v1/device/heartbeat', ['lat' => 999, 'battery' => 250])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'battery']);
    }
}
