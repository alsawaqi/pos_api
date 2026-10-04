<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\ApproverVerifier;
use App\Support\Staff\PinLockedException;
use App\Support\Staff\PinLockout;
use App\Support\Staff\PositionPermissions;
use App\Support\Staff\StaffBranches;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * P-F1 — verify an approver's PIN at a paired device: the online manager-PIN
 * check of verify-manager-pin, unlock-pin-lock and the six PIN-checked
 * actions (card void/refund, kitchen batch cancel, day-end give-away, QR
 * payment review, QR expired cancel, bill combine).
 *
 * LAUNCH-P5 (owner decision 2, H4, M3):
 *  - an approver is an ACTIVE staff member of the device's company whose
 *    position holds `approvals.give` in the resolved tick list
 *    ({@see PositionPermissions}) AND who works at the DEVICE'S BRANCH (home
 *    branch or pos_staff_branches) — no longer any branch of the company;
 *  - PHASE-1A D-5: the per-device manager-PIN lockout ({@see PinLockout},
 *    its own counter, separate from the login one) is evaluated before the
 *    PIN is checked; the 5th consecutive wrong PIN answers 423;
 *  - the verify-manager-pin endpoint (not the six actions, whose refusals
 *    must write nothing) then makes the approver's offline verifier when the
 *    row has none yet ({@see ApproverVerifier}).
 *
 * The operator does NOT have to be the logged-in staff member: any allowed
 * approver's PIN authorizes the action. No match throws — every caller maps
 * it to a generic "invalid PIN", never revealing whether a PIN exists or
 * belongs to a non-approver.
 */
final readonly class VerifyManagerPinAction
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

        $candidates = StaffBranches::worksAt(PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereIn('position', $this->approvalPositions((int) $device->company_id)), (int) $device->branch_id)
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [(int) $device->branch_id])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $staff) {
            if (Hash::check($pin, (string) $staff->pin_hash)) {
                $this->lockout->succeeded($device, PinLockout::MANAGER);

                return $staff;
            }
        }

        $this->lockout->failed($device, PinLockout::MANAGER);

        throw new RuntimeException('Invalid PIN.');
    }

    /**
     * The company's approver positions: those holding `approvals.give` in the
     * resolved tick list (which also feeds the old
     * `manager_approval_positions` config key, so device and server agree).
     *
     * @return list<string>
     */
    public function approvalPositions(int $companyId): array
    {
        return $this->permissions->positionsWith($companyId, 'approvals.give');
    }
}
