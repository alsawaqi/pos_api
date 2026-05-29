<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Phase 8.6 — authenticate a POS staff member by PIN at a paired device
 * (blueprint §11.1 /auth/pos/login + §6.2 staff PIN login).
 *
 * The device is already authenticated (device_token → pos_device guard),
 * so company + branch are known. We scan the ACTIVE staff at that
 * company+branch and bcrypt-check the PIN against each (PINs are unique
 * per company, so at most one matches). A match stamps last_login_at and
 * is returned; no match throws (the controller maps it to a generic 401 —
 * we never reveal whether a PIN exists).
 *
 * Branch scoping enforces §5.4.2: staff only log into devices at their
 * branch. The per-device `pos-login` rate limiter guards the 6-digit PIN
 * brute-force surface. No separate staff token is issued — the device_token
 * already authenticates the API; this just identifies the operator whose
 * id is stamped onto the orders/shifts the device pushes.
 */
final readonly class StaffLoginAction
{
    public function login(Device $device, string $pin): PosStaff
    {
        $candidates = PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->get();

        foreach ($candidates as $staff) {
            if (Hash::check($pin, (string) $staff->pin_hash)) {
                $staff->update(['last_login_at' => now()]);

                return $staff;
            }
        }

        throw new RuntimeException('Invalid PIN.');
    }
}
