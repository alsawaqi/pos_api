<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 *
 * Default: an ASSIGNED, not-yet-paired device (has company/branch,
 * no device_token). Use paired() for a device that's already
 * holding a token, unassigned() for one still sitting in the
 * admin's registered pool.
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'serial_number' => 'SN-'.strtoupper(Str::random(10)),
            'name' => 'Test Terminal',
            'device_type' => 'pos_terminal',
            'company_id' => 1,
            'branch_id' => 1,
            'status' => 'assigned',
            'kiosk_id' => 'KIOSK-'.strtoupper(Str::random(8)),
            'device_token' => null,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => [
            'company_id' => null,
            'branch_id' => null,
            'status' => 'registered',
        ]);
    }

    public function paired(?string $token = null): static
    {
        return $this->state(fn (): array => [
            'device_token' => $token ?? 'mdev_'.Str::random(60),
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
    }
}
