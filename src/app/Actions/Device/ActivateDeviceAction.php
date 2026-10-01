<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Support\DeviceSerial;
use Illuminate\Support\Facades\DB;
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
 *
 * LAUNCH-P1 decision 1a: the code works only on the physical device it was
 * made for. The device reports its hardware serial (and which app it runs);
 * under config('pos.device_serial_binding') = enforce a missing or different
 * serial, or an app that does not match the device type, is refused BEFORE
 * anything is written: the code stays usable, the device's live token and
 * identity are untouched, and a refusal row is recorded for the admin.
 */
final readonly class ActivateDeviceAction
{
    public function handle(string $code, ?DeviceActivationClaim $claim = null): Device
    {
        $claim ??= new DeviceActivationClaim;
        $mode = self::bindingMode();
        $tokenHash = DeviceActivationToken::hash($code);

        /** @var array{0: Device, 1: ?int, 2: list<string>} $outcome */
        $outcome = DB::transaction(function () use ($tokenHash, $claim, $mode): array {
            // Lock the device first, like assignment/revocation. Then re-read
            // the code under lock so a move or a second activation cannot race.
            $deviceId = DeviceActivationToken::query()->where('token_hash', $tokenHash)->value('device_id');
            $device = Device::query()->whereKey($deviceId)->lockForUpdate()->first();
            $token = DeviceActivationToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if ($token === null || ! $token->isUsable()) {
                throw new RuntimeException('Activation failed: invalid or expired code.');
            }

            if ($device !== null && $device->assigned_at !== null
                && ($token->created_at === null || $token->created_at->lt($device->assigned_at))) {
                throw new RuntimeException('Activation failed: invalid or expired code.');
            }
            if ($device === null || ! $device->isAssigned()) {
                throw new RuntimeException('Activation failed: device is not assigned to a branch.');
            }
            if (in_array($device->status, ['blocked', 'inactive'], true)) {
                throw new RuntimeException('Activation failed: device is not active.');
            }

            $issues = self::bindingIssues($device, $claim, $mode);
            if ($mode === 'enforce' && $issues !== []) {
                // Nothing written: no used_at, no new credential.
                return [$device, (int) $token->getKey(), $issues];
            }

            $token->update(['used_at' => now()]);

            $reported = DeviceSerial::normalize($claim->serial);
            $device->forceFill([
                // Only a serial that matched the registered one counts as verified.
                'serial_verified_at' => $reported !== null
                    && $reported === DeviceSerial::normalize($device->serial_number) ? now() : null,
            ]);
            $device->issueCredential();

            return [$device, (int) $token->getKey(), $issues];
        });

        [$device, $tokenId, $issues] = $outcome;
        // Recorded after the transaction so a refusal row survives the refusal.
        foreach ($issues as $reason) {
            self::record($device, $tokenId, $reason, $mode === 'enforce' ? 'refused' : 'reported', $mode, $claim);
            if ($mode === 'enforce') {
                throw new DeviceActivationRefused($reason);
            }
        }

        return $device;
    }

    /** off | report | enforce — anything else fails closed to enforce. */
    public static function bindingMode(): string
    {
        $mode = strtolower(trim((string) config('pos.device_serial_binding', 'enforce')));

        return in_array($mode, ['off', 'report', 'enforce'], true) ? $mode : 'enforce';
    }

    /** @return list<string> refusal codes, most fundamental first */
    private static function bindingIssues(Device $device, DeviceActivationClaim $claim, string $mode): array
    {
        if ($mode === 'off') {
            return [];
        }
        $issues = [];
        $reported = DeviceSerial::normalize($claim->serial);
        if ($reported === null) {
            $issues[] = DeviceActivationRefused::SERIAL_MISSING;
        } elseif ($reported !== DeviceSerial::normalize($device->serial_number)) {
            $issues[] = DeviceActivationRefused::DEVICE_MISMATCH;
        }
        if ($claim->app !== null && ! $device->acceptsActivationApp($claim->app)) {
            $issues[] = DeviceActivationRefused::APP_MISMATCH;
        }

        return $issues;
    }

    private static function record(Device $device, int $tokenId, string $reason, string $outcome, string $mode, DeviceActivationClaim $claim): void
    {
        DB::table('pos_device_activation_attempts')->insert([
            'device_id' => $device->getKey(),
            'activation_token_id' => $tokenId,
            'outcome' => $outcome,
            'reason' => $reason,
            'binding_mode' => $mode,
            'reported_serial_masked' => DeviceSerial::mask($claim->serial),
            'reported_serial_hash' => DeviceSerial::hash($claim->serial),
            'app' => $claim->app !== null ? mb_substr($claim->app, 0, 32) : null,
            'manufacturer' => $claim->manufacturer !== null ? mb_substr($claim->manufacturer, 0, 128) : null,
            'model' => $claim->model !== null ? mb_substr($claim->model, 0, 128) : null,
            'ip_address' => $claim->ip,
            'created_at' => now(),
        ]);
    }
}
