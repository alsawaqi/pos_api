<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8 — device pairing (blueprint §11.1 /auth/device/pair).
 *
 * The device presents its kiosk_id + a one-time activation token
 * (minted by the Admin Portal as SHA-256(token_hash)). On success
 * we mint a long-lived device_token, store it on the device, and
 * return the device — the controller hands the plaintext token back
 * to the caller (it's the Bearer credential for every later call).
 *
 * Guards:
 *   - kiosk_id must resolve to a device
 *   - the device must be assigned to a company + branch
 *   - the activation token must be usable (unused, unrevoked, unexpired)
 *
 * The activation token is single-use: pairing stamps used_at so a
 * leaked/replayed token can't pair a second time.
 *
 * Throws RuntimeException on any failure (the controller maps it to
 * a 422). Errors are deliberately generic so pairing can't be used
 * to enumerate valid kiosk IDs.
 */
final readonly class PairDeviceAction
{
    public function handle(string $kioskId, string $activationToken): Device
    {
        $device = Device::query()->where('kiosk_id', $kioskId)->first();
        if ($device === null) {
            throw new RuntimeException('Pairing failed: invalid kiosk or activation token.');
        }
        if (! $device->isAssigned()) {
            throw new RuntimeException('Pairing failed: device is not assigned to a branch.');
        }

        $token = $device->activationTokens()
            ->where('token_hash', DeviceActivationToken::hash($activationToken))
            ->first();
        if ($token === null || ! $token->isUsable()) {
            throw new RuntimeException('Pairing failed: invalid kiosk or activation token.');
        }

        return DB::transaction(function () use ($device, $token): Device {
            $token->update(['used_at' => now()]);

            // The bearer credential. Stored plaintext in the (UNIQUE)
            // device_token column so the pos_device guard can match it
            // directly. 'mdev_' + 60 = 65 chars, within the varchar(80).
            $device->update([
                'device_token' => 'mdev_'.Str::random(60),
                'last_seen_at' => now(),
            ]);

            return $device->fresh();
        });
    }
}
