<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 — the branch's shift-end reminder time (owner decision 8):
 * pos_branch_settings key `shift_end_reminder_at`, "HH:MM" in the business
 * timezone (Asia/Muscat), or null (off). Anything that is not a valid 24-hour
 * "HH:MM" reads as off.
 */
final class ShiftEndReminder
{
    public const KEY = 'shift_end_reminder_at';

    public static function forBranch(int $companyId, int $branchId): ?string
    {
        $raw = DB::table('pos_branch_settings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('key', self::KEY)
            ->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_string($value)) {
            // A bare string stored without JSON quoting still reads.
            $value = is_string($raw) && json_last_error() !== JSON_ERROR_NONE ? $raw : null;
        }

        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) === 1 ? trim($value) : null;
    }
}
