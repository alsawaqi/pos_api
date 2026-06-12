<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PosStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * P-G1.6 — verify a KITCHEN staff member's PIN at a paired device: the
 * walk-up gate for the Kitchen screen. When the logged-in staff member's
 * position is not in the merchant's `kitchen_positions` policy, the
 * device prompts for a kitchen staff code instead of forcing a
 * logout/login dance — the chef walks to the till, punches their code,
 * and the Kitchen session runs AS them (batches attribute to the actual
 * chef, not the cashier whose till it is).
 *
 * The VerifyManagerPinAction mechanics verbatim, against the
 * `kitchen_positions` policy (default managers-only, the same default
 * BuildDeviceConfigAction emits): ACTIVE staff of the device's company
 * whose position is allowed, own-branch first, bcrypt check per
 * candidate. No match throws — the controller maps it to the same
 * generic 401 invalid_pin, never revealing whether a PIN exists.
 */
final readonly class VerifyKitchenPinAction
{
    public function verify(Device $device, string $pin): PosStaff
    {
        $candidates = PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereIn('position', $this->kitchenPositions((int) $device->company_id))
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
     * The company's `kitchen_positions` policy, defaulting to managers-only
     * when unset — the same read + normalisation
     * BuildDeviceConfigAction::positionListSetting() emits in
     * /device/config, so the device-side gate and this server-side check
     * can't diverge.
     *
     * @return list<string>
     */
    private function kitchenPositions(int $companyId): array
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'kitchen_positions')
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
