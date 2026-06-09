<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/device/shift/current — the device's currently-open shift (or null),
 * so a device that lost its local shift record can ADOPT the server's existing
 * open shift instead of being stuck on the open-shift screen. Branch/device
 * scoped, money as integer baisas.
 */
class DeviceCurrentShiftTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_shift', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function shift(Device $device, array $overrides = []): Shift
    {
        return Shift::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->getKey(),
            'staff_id' => 7,
            'opened_at' => now(),
            'opening_cash' => '5.000',
            'status' => Shift::STATUS_OPEN,
        ], $overrides));
    }

    public function test_returns_the_devices_open_shift(): void
    {
        $device = $this->device();
        $shift = $this->shift($device);

        $res = $this->withToken('mdev_shift')->getJson('/api/v1/device/shift/current')->assertOk();

        $res->assertJsonPath('meta.money_unit', 'baisas');
        $this->assertSame($shift->uuid, $res->json('data.shift.uuid'));
        $this->assertSame(5000, $res->json('data.shift.opening_cash_baisas'));
        $this->assertSame(7, $res->json('data.shift.staff_id'));
    }

    public function test_returns_null_when_no_open_shift(): void
    {
        $this->device();

        $res = $this->withToken('mdev_shift')->getJson('/api/v1/device/shift/current')->assertOk();
        $this->assertNull($res->json('data.shift'));
    }

    public function test_excludes_a_closed_shift(): void
    {
        $device = $this->device();
        $this->shift($device, ['status' => 'closed']);

        $res = $this->withToken('mdev_shift')->getJson('/api/v1/device/shift/current')->assertOk();
        $this->assertNull($res->json('data.shift'));
    }

    public function test_is_scoped_to_the_device(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other', 100, 10); // same branch, different device
        $this->shift($other);

        $res = $this->withToken('mdev_shift')->getJson('/api/v1/device/shift/current')->assertOk();
        $this->assertNull($res->json('data.shift'));
    }

    public function test_requires_a_device_token(): void
    {
        $this->getJson('/api/v1/device/shift/current')->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned_shift')->create(['company_id' => null, 'branch_id' => null]);
        $this->withToken('mdev_unassigned_shift')->getJson('/api/v1/device/shift/current')->assertStatus(409);
    }
}
