<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Branch;
use App\Models\Floor;
use App\Models\QrSession;
use App\Models\Table;

final class TableCardIdentity
{
    public function valid(QrSession $session): bool
    {
        if ($session->origin !== 'table_card') {
            return true;
        }
        $table = Table::query()->whereKey($session->table_id)->where('company_id', $session->company_id)->where('status', 'active')->first();
        if ($table === null || $session->table_qr_token_hash === null
            || ! hash_equals($session->table_qr_token_hash, hash('sha256', (string) $table->qr_token))) {
            return false;
        }

        return Floor::query()->whereKey($table->floor_id)->where('branch_id', $session->branch_id)->where('status', 'active')->exists()
            && Branch::query()->whereKey($session->branch_id)->where('company_id', $session->company_id)->exists()
            && app(QrTableCardEnabled::class)->forBranch((int) $session->company_id, (int) $session->branch_id);
    }
}
