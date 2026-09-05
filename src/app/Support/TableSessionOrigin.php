<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\TableSession;
use InvalidArgumentException;

final class TableSessionOrigin
{
    public static function forDevice(Device $device): string
    {
        return match ($device->device_type) {
            'fixed_pos' => TableSession::ORIGIN_STAFF_TILL,
            'handheld' => TableSession::ORIGIN_STAFF_HANDHELD,
            default => throw new InvalidArgumentException('Only attended devices can seat a table.'),
        };
    }
}
