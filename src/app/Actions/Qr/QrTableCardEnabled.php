<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Support\Facades\DB;

/** Uncached branch-only card switch. Only the JSON string "on" enables it. */
final class QrTableCardEnabled
{
    public function forBranch(int $companyId, int $branchId): bool
    {
        $raw = DB::table('pos_branch_settings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('key', 'qr_table_card_enabled')
            ->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return $value === 'on';
    }
}
