<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
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
            'counted_pieces' => ScaledDecimal::class.':3,4',
            'counted_units' => ScaledDecimal::class.':3,4',
            'expected_units' => ScaledDecimal::class.':3,4',
            'variance_units' => ScaledDecimal::class.':3,4',
            'unit_cost_at_time' => ScaledDecimal::class.':3,6',
            // LAUNCH-P2 P2-6 — late pre-count movements folded into the line.
            'late_movement_units' => ScaledDecimal::class.':3,4',
        ];
    }
}
