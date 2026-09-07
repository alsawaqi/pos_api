<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Support\Facades\DB;

/** Uncached, branch-only location policy; malformed values default to advisory. */
final class QrScanGeofenceMode
{
    public const VALUES = ['off', 'advisory', 'enforce'];

    public function forBranch(int $companyId, int $branchId): string
    {
        $raw = DB::table('pos_branch_settings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('key', 'qr_scan_geofence_mode')
            ->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($value) && in_array($value, self::VALUES, true) ? $value : 'advisory';
    }
}
