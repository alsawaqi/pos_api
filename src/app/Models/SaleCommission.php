<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Device\Sync\RecordSaleCommissionAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only per-sale commission breakdown (pos_sale_commissions, schema
 * owned by pos_admin). Written by {@see RecordSaleCommissionAction}
 * at order.pay — one row per party (platform / bank / other / merchant).
 * Unguarded like the other sync-target models.
 */
class SaleCommission extends Model
{
    protected $table = 'pos_sale_commissions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'gross_amount' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }
}
