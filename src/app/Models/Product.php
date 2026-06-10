<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_products` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 * Money columns are decimal(12,3) OMR — the assembler converts them to
 * integer baisas on the wire.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $table = 'pos_products';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:3',
            'delivery_price' => 'decimal:3',
            'cost_price' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'display_order' => 'integer',
            // Phase D2 — catalogue flags.
            'low_stock_threshold' => 'decimal:3',
            'tax_inclusive' => 'boolean',
            'show_on_customer_tablet' => 'boolean',
        ];
    }
}
