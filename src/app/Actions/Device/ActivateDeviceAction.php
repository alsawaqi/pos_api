<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Device activation by a single one-time code (POST /auth/device/activate).
 *
 * The admin mints a per-device activation code in pos_admin (after assigning
 * the device to a branch + terminal). The installer enters that ONE code on the
 * machine. Because the code is globally unique we look it up directly (no
 * kiosk_id needed on the device), resolve its device, validate, and mint a
 * long-lived device_token. The controller returns the device's kiosk_id +
 * terminal_id so the device can store them for the Soft POS / Mosambee.
 *
 * Single-use: activation stamps used_at. Errors are generic (no enumeration).
 */
final readonly class ActivateDeviceAction
{
    public function handle(string $code): Device
    {
        $token = DeviceActivationToken::query()
            ->where('token_hash', DeviceActivationToken::hash($code))
            ->first();
        if ($token === null || ! $token->isUsable()) {
            throw new RuntimeException('Activation failed: invalid or expired code.');
        }

        $device = $token->device;
        if ($device === null || ! $device->isAssigned()) {
            throw new RuntimeException('Activation failed: device is not assigned to a branch.');
        }
        if (in_array($device->status, ['blocked', 'inactive'], true)) {
            throw new RuntimeException('Activation failed: device is not active.');
        }

        return DB::transaction(function () use ($device, $token): Device {
            $token->update(['used_at' => now()]);

            // The bearer credential, stored plaintext in the (UNIQUE) device_token
            // column so the pos_device guard matches it directly.
            $device->update([
                'device_token' => 'mdev_'.Str::random(60),
                'last_seen_at' => now(),
            ]);

            return $device->fresh();
        });
    }
}
