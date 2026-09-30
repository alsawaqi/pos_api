<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

final class ReadQrBranding
{
    /** @return array<string, mixed> */
    public function handle(int $companyId, int $branchId): array
    {
        $company = DB::table('pos_companies')->where('id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('status', ['suspended', 'inactive'])->first(['name', 'name_ar']);
        $branch = $company === null ? null : Branch::query()->whereKey($branchId)->where('company_id', $companyId)->first();
        $logo = $branch?->receipt_template['logo_base64'] ?? null;

        return [
            'merchant' => ['name' => $company?->name, 'name_ar' => $company?->name_ar],
            'branch' => ['name' => $branch?->name, 'name_ar' => $branch?->name_ar],
            'logo_base64' => is_string($logo) && trim($logo) !== '' ? $logo : null,
        ];
    }
}
