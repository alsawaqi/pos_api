<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase D1 — append-only PRODUCT-unit stock ledger
 * (pos_product_stock_movements; schema owned by pos_admin
 * 2026_06_25_010200).
 *
 * pos_api only ever appends `sale_consumption` rows: negative quantities at
 * order.pay, positive reversals at order.void — mirroring the ingredient
 * ledger ({@see StockMovement}). The merchant portal owns the other types
 * (received / allocation / transfer / adjustment / waste) and CASTS
 * movement_type to a backed enum, so only its known strings may be written.
 *
 * A sale row carries branch_id (branch side of the ledger); the CENTRAL
 * pos_product_stock balance is untouched — its invariant sums only the
 * branch_id-NULL rows. Writes happen inside the Pay/Void handler's
 * transaction via {@see ConsumeInventoryAction}.
 *
 * The table has only created_at + occurred_at — timestamps are disabled and
 * set explicitly.
 */
class ProductStockMovement extends Model
{
    public $timestamps = false;

    protected $table = 'pos_product_stock_movements';

    protected $guarded = [];

    public const TYPE_SALE_CONSUMPTION = 'sale_consumption';

    // P-G1 kitchen production: a finished batch lands its pieces in the
    // branch shelf stock (positive, branch side). Merchant-side enum case:
    // ProductStockMovementType::Produced.
    public const TYPE_PRODUCED = 'produced';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
