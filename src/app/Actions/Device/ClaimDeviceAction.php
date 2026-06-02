<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Device claim by terminal_id (POST /auth/device/claim).
 *
 * Alternative to kiosk_id + activation-token pairing for deployments that
 * identify a device by the bank terminal_id the admin set at device→branch
 * assignment. The device presents its terminal_id (configured once at install);
 * we resolve the single ASSIGNED device carrying it and mint a long-lived
 * device_token, binding the terminal to its branch.
 *
 * Trade-off vs pairing: there is no one-time secret — knowing a terminal_id is
 * enough to claim that device — so the route is throttled and errors are
 * generic (no enumeration). terminal_id is only unique per bank, so an
 * ambiguous match (same id across banks) is rejected rather than guessed.
 */
final readonly class ClaimDeviceAction
{
    public function handle(string $terminalId): Device
    {
        $devices = Device::query()
            ->where('terminal_id', $terminalId)
            ->whereNotNull('company_id')
            ->whereNotNull('branch_id')
            ->whereNotIn('status', ['blocked', 'inactive'])
            ->get();

        // 0 = unknown / unassigned; >1 = ambiguous across banks.
        if ($devices->count() !== 1) {
            throw new RuntimeException('Claim failed: unknown or ambiguous terminal ID.');
        }

        /** @var Device $device */
        $device = $devices->first();

        return DB::transaction(function () use ($device): Device {
            // The bearer credential. Stored plaintext in the (UNIQUE)
            // device_token column so the pos_device guard matches it directly.
            $device->update([
                'device_token' => 'mdev_'.Str::random(60),
                'last_seen_at' => now(),
            ]);

            return $device->fresh();
        });
    }
}
