<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\ApproverVerifier;
use App\Support\Staff\PinLockedException;
use App\Support\Staff\PinLockout;
use App\Support\Staff\StaffBranches;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Phase 8.6 — authenticate a POS staff member by PIN at a paired device
 * (blueprint §11.1 /auth/pos/login + §6.2 staff PIN login).
 *
 * The device is already authenticated (device_token → pos_device guard),
 * so company + branch are known. We scan the ACTIVE staff of that company
 * who work at the device's branch and bcrypt-check the PIN against each (PINs
 * are unique per company, so at most one matches). A match stamps
 * last_login_at and is returned; no match throws (the controller maps it to
 * a generic 401 — we never reveal whether a PIN exists).
 *
 * LAUNCH-P5:
 *  - "works at the branch" = the home branch or a pos_staff_branches row
 *    (staff at several branches with one PIN);
 *  - PHASE-1A D-3: the per-device login lockout is evaluated BEFORE the PIN
 *    is checked; the 5th consecutive wrong PIN answers 423, a correct PIN
 *    clears the counter ({@see PinLockout});
 *  - a successful login makes the staff member's offline approval verifier
 *    when the row has none yet ({@see ApproverVerifier}).
 *
 * The device_token authenticates the API; this identifies the operator whose
 * id is stamped onto the orders/shifts the device pushes. The controller
 * returns a signed staff token for that operator on that device
 * ({@see \App\Support\Staff\StaffToken}, LAUNCH-P5 fix order 1 F1).
 */
final readonly class StaffLoginAction
{
    public function __construct(private PinLockout $lockout) {}

    /**
     * @throws PinLockedException
     */
    public function login(Device $device, string $pin): PosStaff
    {
        $this->lockout->guard($device, PinLockout::LOGIN);

        $candidates = StaffBranches::worksAt(PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE), (int) $device->branch_id)
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [(int) $device->branch_id])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $staff) {
            if (Hash::check($pin, (string) $staff->pin_hash)) {
                $this->lockout->succeeded($device, PinLockout::LOGIN);
                $staff->update(['last_login_at' => now()]);
                ApproverVerifier::ensureFor($staff, $pin);

                return $staff;
            }
        }

        $this->lockout->failed($device, PinLockout::LOGIN);

        throw new RuntimeException('Invalid PIN.');
    }
}
