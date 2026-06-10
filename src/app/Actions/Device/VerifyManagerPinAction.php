<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * P-F1 — verify a manager's PIN at a paired device, the fallback for the
 * fingerprint gate on sensitive POS actions (comps, cancellations, gifts).
 *
 * The device is already authenticated (device_token → pos_device guard), so
 * the company is known. We scan the ACTIVE staff of that company whose
 * position is in the company's `manager_approval_positions` policy
 * (pos_company_settings; default managers-only, mirroring what
 * BuildDeviceConfigAction emits to the device) and bcrypt-check the PIN
 * against each — the StaffLoginAction mechanism. The operator does NOT have
 * to be the logged-in staff member: any allowed staff member's PIN
 * authorizes the action.
 *
 * Branch scoping (deliberate choice): unlike PIN *login* (strictly
 * branch-bound, §5.4.2), approval ACCEPTS any branch of the company so a
 * roaming area manager can authorize at whichever branch they're visiting.
 * Staff of the device's own branch are checked FIRST, so on the (already
 * company-unique) off chance of a PIN collision the local manager wins.
 *
 * No match throws — the controller maps it to the same generic 401
 * invalid_pin the login endpoint uses; we never reveal whether a PIN exists
 * or belongs to a non-approved position.
 */
final readonly class VerifyManagerPinAction
{
    public function verify(Device $device, string $pin): PosStaff
    {
        $candidates = PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereIn('position', $this->approvalPositions((int) $device->company_id))
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [(int) $device->branch_id])
            ->get();

        foreach ($candidates as $staff) {
            if (Hash::check($pin, (string) $staff->pin_hash)) {
                return $staff;
            }
        }

        throw new RuntimeException('Invalid PIN.');
    }

    /**
     * The company's `manager_approval_positions` policy, defaulting to
     * managers-only when unset — the same read + normalisation
     * BuildDeviceConfigAction::positionListSetting() emits in /device/config,
     * so the device-side gate and this server-side check can't diverge.
     *
     * @return list<string>
     */
    private function approvalPositions(int $companyId): array
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'manager_approval_positions')
            ->value('value');

        $positions = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($positions)) {
            $positions = [];
        }

        $positions = array_values(array_filter(
            array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $positions),
            static fn (string $p): bool => $p !== '',
        ));

        return $positions === [] ? ['manager'] : $positions;
    }
}
