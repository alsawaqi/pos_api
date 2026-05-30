<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasting\BranchChannel;
use App\Broadcasting\CompanyChannel;
use App\Broadcasting\DeviceChannel;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §11.5 — broadcast channel authorization.
 *
 * Two layers:
 *   - The /api/v1/broadcasting/auth ENDPOINT must sit behind the `pos_device`
 *     guard (rejects a tokenless request) — covered over HTTP.
 *   - The per-channel PREDICATE (own scope only) lives in App\Broadcasting\*
 *     channel classes — unit-tested directly, because the test broadcaster is
 *     `null` (phpunit.xml), whose HTTP auth() is a permissive no-op and so
 *     can't exercise the deny path.
 */
class DeviceBroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->paired('mdev_auth')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    // ---- endpoint plumbing (HTTP) ----

    public function test_the_auth_endpoint_requires_a_device_token(): void
    {
        $this->device();

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-branch.10',
        ])->assertUnauthorized();
    }

    public function test_a_paired_device_can_reach_the_auth_endpoint_for_its_own_branch(): void
    {
        $this->device();

        // With the `null` broadcaster this returns 200 (the driver no-ops the
        // body); the point under test is that the guard let the request THROUGH
        // rather than 401-ing it. The deny logic is unit-tested below.
        $this->withToken('mdev_auth')->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-branch.10',
        ])->assertOk();
    }

    // ---- channel predicates (unit) ----

    public function test_company_channel_allows_only_the_devices_own_company(): void
    {
        $device = $this->device(); // company_id = 100
        $channel = new CompanyChannel;

        $this->assertTrue($channel->join($device, 100));
        $this->assertFalse($channel->join($device, 999));
    }

    public function test_branch_channel_allows_only_the_devices_own_branch(): void
    {
        $device = $this->device(); // branch_id = 10
        $channel = new BranchChannel;

        $this->assertTrue($channel->join($device, 10));
        $this->assertFalse($channel->join($device, 999));
    }

    public function test_branch_channel_denies_a_branchless_device(): void
    {
        $device = Device::factory()->paired('mdev_nobranch')->create(['company_id' => 100, 'branch_id' => null]);
        $channel = new BranchChannel;

        $this->assertFalse($channel->join($device, 10));
    }

    public function test_device_channel_allows_only_the_device_itself(): void
    {
        $device = $this->device();
        $channel = new DeviceChannel;

        $this->assertTrue($channel->join($device, (int) $device->id));
        $this->assertFalse($channel->join($device, (int) $device->id + 1));
    }
}
