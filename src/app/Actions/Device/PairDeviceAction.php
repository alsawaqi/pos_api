<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\DeviceActivationToken;
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
    public function handle(string $kioskId, string $activationToken, ?DeviceActivationClaim $claim = null): Device
    {
        $deviceId = Device::query()->where('kiosk_id', $kioskId)->value('id');
        $codeDeviceId = DeviceActivationToken::query()
            ->where('token_hash', DeviceActivationToken::hash($activationToken))->value('device_id');
        if ($deviceId === null || (int) $deviceId !== (int) $codeDeviceId) {
            throw new RuntimeException('Pairing failed: invalid kiosk or activation token.');
        }

        // The same serial/app lock as single-code activation (LAUNCH-P1 1a).
        return app(ActivateDeviceAction::class)->handle($activationToken, $claim);
    }
}
