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
        $tokenHash = DeviceActivationToken::hash($code);

        return DB::transaction(function () use ($tokenHash): Device {
            $token = DeviceActivationToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if ($token === null || ! $token->isUsable()) {
                throw new RuntimeException('Activation failed: invalid or expired code.');
            }

            $device = Device::query()
                ->whereKey($token->device_id)
                ->lockForUpdate()
                ->first();
            if ($device === null || ! $device->isAssigned()) {
                throw new RuntimeException('Activation failed: device is not assigned to a branch.');
            }
            if (in_array($device->status, ['blocked', 'inactive'], true)) {
                throw new RuntimeException('Activation failed: device is not active.');
            }

            $token->update(['used_at' => now()]);

            // Mint the bearer credential and promote the assigned device into its
            // operable state atomically. QR station guards deliberately require
            // status=active, so activation is the lifecycle boundary that makes a
            // newly enrolled station usable.
            $device->update([
                'device_token' => 'mdev_'.Str::random(60),
                'status' => 'active',
                'last_seen_at' => now(),
            ]);

            return $device->fresh();
        });
    }
}
