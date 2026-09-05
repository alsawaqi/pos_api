<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Support\Facades\DB;

/** Reads the per-branch dine-in round policy with company fallback, uncached on the money path. */
final class DineInRoundMode
{
    public const KITCHEN_DIRECT = 'kitchen_direct';

    public const STAFF_CONFIRM = 'staff_confirm';

    /** @var list<string> */
    public const VALUES = [self::KITCHEN_DIRECT, self::STAFF_CONFIRM];

    public function forBranch(int $companyId, int $branchId): string
    {
        $raw = DB::table('pos_branch_settings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('key', 'dine_in_round_mode')
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($value) && in_array($value, self::VALUES, true)
            ? $value
            : $this->forCompany($companyId);
    }

    public function forCompany(int $companyId): string
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'dine_in_round_mode')
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($value) && in_array($value, self::VALUES, true)
            ? $value
            : self::KITCHEN_DIRECT;
    }
}
