<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_customers` table.
 *
 * Served as a thin cache slice in the device config bundle (Phase 8.1)
 * so cashiers can look customers up offline. Volatile loyalty balances
 * (stamps/points, held in pos_loyalty_accounts since the loyalty
 * refactor) are intentionally NOT shipped here — only `wallet_balance`.
 * Note: `points_balance` was dropped from this table in that refactor.
 */
class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'pos_customers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_balance' => 'decimal:3',
        ];
    }
}
