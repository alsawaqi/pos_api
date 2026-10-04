<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\PinLockedException;
use App\Support\Staff\PinLockout;
use App\Support\Staff\PositionPermissions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * P-G1.6 — verify a KITCHEN staff member's PIN at a paired device: the
 * walk-up gate for the Kitchen screen. When the logged-in staff member's
 * position may not open the Kitchen screen, the device prompts for a kitchen
 * staff code instead of forcing a logout/login dance — the chef walks to the
 * till, punches their code, and the Kitchen session runs AS them (batches
 * attribute to the actual chef, not the cashier whose till it is).
 *
 * LAUNCH-P5: the allowed positions are those holding `kitchen.screen` in the
 * resolved tick list (the kitchen position always does) — the same set the
 * config's `kitchen_positions` key carries. The kitchen PIN stays. Because it
 * answers whether a PIN is valid (managers included), it shares the
 * manager-PIN lockout ({@see PinLockout::MANAGER}, PHASE-1A D-5).
 *
 * ACTIVE staff of the device's company whose position is allowed, own-branch
 * first, bcrypt check per candidate. No match throws — the controller maps it
 * to the same generic 401 invalid_pin, never revealing whether a PIN exists.
 */
final readonly class VerifyKitchenPinAction
{
    public function __construct(
        private PinLockout $lockout,
        private PositionPermissions $permissions,
    ) {}

    /**
     * @throws PinLockedException
     */
    public function verify(Device $device, string $pin): PosStaff
    {
        $this->lockout->guard($device, PinLockout::MANAGER);

        $candidates = PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereIn('position', $this->permissions->positionsWith((int) $device->company_id, 'kitchen.screen'))
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [(int) $device->branch_id])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $staff) {
            if (Hash::check($pin, (string) $staff->pin_hash)) {
                // Fix order 1 L1 — a kitchen worker's own PIN never clears the
                // manager-PIN failure counter; only a person who could also
                // pass the manager-PIN check (approvals.give) does.
                if ($this->permissions->allows((int) $device->company_id, (string) $staff->position, 'approvals.give')) {
                    $this->lockout->succeeded($device, PinLockout::MANAGER);
                }

                return $staff;
            }
        }

        $this->lockout->failed($device, PinLockout::MANAGER);

        throw new RuntimeException('Invalid PIN.');
    }
}
