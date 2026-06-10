<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase A — minimal mirror of the shared `pos_waste_records` table
 * (schema owned by pos_admin's 2026_05_31 migration; the merchant
 * portal owns the manual waste flow).
 *
 * pos_api writes here from exactly ONE path: the day-end stock
 * count handler, when the physical count comes in below the running
 * balance (reason = reconciliation_variance). Quantity is ALWAYS
 * POSITIVE; the mirroring stock movement carries the negative.
 */
class WasteRecord extends Model
{
    protected $table = 'pos_waste_records';

    protected $guarded = [];

    public const REASON_RECONCILIATION_VARIANCE = 'reconciliation_variance';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost_at_time' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }
}
