<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use Illuminate\Support\Facades\DB;

/** Branch-only rollout switch. Missing or malformed values fail closed to off. */
final class TableSessionsMode
{
    public const VALUES = ['off', 'shadow', 'live'];

    public function forBranch(int $companyId, int $branchId): string
    {
        $raw = DB::table('pos_branch_settings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('key', 'table_sessions_mode')
            ->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($value) && in_array($value, self::VALUES, true) ? $value : 'off';
    }
}
