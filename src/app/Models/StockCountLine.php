<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase A (Additions §2.8) — one ingredient line of a day-end stock
 * count (pos_stock_count_lines). Immutable child of StockCount; no
 * timestamps on the table.
 */
class StockCountLine extends Model
{
    public $timestamps = false;

    protected $table = 'pos_stock_count_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_pieces' => 'decimal:3',
            'counted_units' => 'decimal:3',
            'expected_units' => 'decimal:3',
            'variance_units' => 'decimal:3',
            'unit_cost_at_time' => 'decimal:3',
        ];
    }
}
