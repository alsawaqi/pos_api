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
            'is_shared' => true, // HH-2 default; legacy rows override false
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

    public function test_is_scoped_to_the_device_without_a_staff_param(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other', 100, 10); // same branch, different device
        $this->shift($other);

        $res = $this->withToken('mdev_shift')->getJson('/api/v1/device/shift/current')->assertOk();
        $this->assertNull($res->json('data.shift'));
    }

    /**
     * HH-2 — the same staff member's shift is SHARED across devices: probing
     * with ?staff_id finds their open shift even when another device at the
     * branch opened it, so the second device adopts instead of re-opening.
     */
    public function test_staff_param_finds_the_staffs_shift_from_another_device(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other', 100, 10);
        $shift = $this->shift($other); // staff 7 opened on the OTHER device

        $res = $this->withToken('mdev_shift')
            ->getJson('/api/v1/device/shift/current?staff_id=7')
            ->assertOk();

        $this->assertSame($shift->uuid, $res->json('data.shift.uuid'));
        $this->assertSame(7, $res->json('data.shift.staff_id'));
        $this->assertSame($other->getKey(), $res->json('data.shift.device_id'));
    }

    /**
     * A LEGACY per-device shift (opened by an old build, is_shared=false)
     * never travels: its close attributes by device only, so adopting it on
     * another terminal would silently lose that terminal's sales from its Z.
     */
    public function test_staff_param_ignores_a_legacy_shift_on_another_device(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other', 100, 10);
        $this->shift($other, ['is_shared' => false]); // staff 7's legacy drawer

        $res = $this->withToken('mdev_shift')
            ->getJson('/api/v1/device/shift/current?staff_id=7')
            ->assertOk();

        $this->assertNull($res->json('data.shift'));
    }

    public function test_staff_param_ignores_another_staffs_shift_elsewhere(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other', 100, 10);
        $this->shift($other, ['staff_id' => 99]); // someone else's drawer

        $res = $this->withToken('mdev_shift')
            ->getJson('/api/v1/device/shift/current?staff_id=7')
            ->assertOk();

        $this->assertNull($res->json('data.shift'));
    }

    /**
     * The device fallback stays: a different staff member logging into a
     * device whose drawer is already open inherits it (pos_machine's
     * logout-without-close flow), staff param or not.
     */
    public function test_staff_param_falls_back_to_this_devices_open_shift(): void
    {
        $device = $this->device('mdev_shift', 100, 10);
        $shift = $this->shift($device, ['staff_id' => 99]); // opened by other staff HERE

        $res = $this->withToken('mdev_shift')
            ->getJson('/api/v1/device/shift/current?staff_id=7')
            ->assertOk();

        $this->assertSame($shift->uuid, $res->json('data.shift.uuid'));
        $this->assertSame(99, $res->json('data.shift.staff_id'));
    }

    public function test_staff_param_is_scoped_to_the_branch(): void
    {
        $this->device('mdev_shift', 100, 10);
        $other = $this->device('mdev_other_branch', 100, 20); // other branch
        $this->shift($other); // staff 7's shift, but at branch 20

        $res = $this->withToken('mdev_shift')
            ->getJson('/api/v1/device/shift/current?staff_id=7')
            ->assertOk();

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
