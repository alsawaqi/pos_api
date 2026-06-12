<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.3 — append-only stock ledger (pos_stock_movements, §5.6.3/§10.5).
 *
 * pos_api only ever appends the sale-driven rows: sale_consumption and
 * addon_consumption (negative quantities on pay), and their positive
 * reversals on void. The merchant portal owns the other types (restock,
 * waste, adjustment…). Σ(quantity) per (branch, ingredient) MUST equal
 * pos_branch_stock.quantity — {@see ConsumeInventoryAction}
 * keeps both writes in one transaction.
 *
 * Like {@see SyncEvent}, the table has NO created_at/updated_at pair — only
 * created_at + occurred_at — so timestamps are disabled and set explicitly.
 */
class StockMovement extends Model
{
    public $timestamps = false;

    protected $table = 'pos_stock_movements';

    protected $guarded = [];

    public const TYPE_INITIAL = 'initial';

    public const TYPE_RESTOCK = 'restock';

    public const TYPE_SALE_CONSUMPTION = 'sale_consumption';

    public const TYPE_ADDON_CONSUMPTION = 'addon_consumption';

    public const TYPE_WASTE = 'waste';

    public const TYPE_LOSS = 'loss';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    // P-G1 kitchen production: ingredients leave the branch shelf when the
    // chef STARTS a batch (negative), and come back if a manager cancels
    // the in-progress batch (positive).
    public const TYPE_PRODUCTION_CONSUMPTION = 'production_consumption';

    public const TYPE_PRODUCTION_RETURN = 'production_return';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost_at_time' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
