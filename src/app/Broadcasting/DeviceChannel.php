<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Device;

/**
 * §11.5 — authorizes a device to join its point-to-point channel (device.{id}),
 * used for targeted commands to one terminal. A device may join ONLY its own.
 * See {@see CompanyChannel} for why this is a class, not a closure.
 */
final class DeviceChannel
{
    public function join(Device $device, int $deviceId): bool
    {
        return (int) $device->id === $deviceId;
    }
}
