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
            // LAUNCH-P4 — channels (product_type / branch_scope stay strings).
            'sold_in_store' => 'boolean',
            'sold_on_delivery' => 'boolean',
        ];
    }

    public const TYPE_STANDARD = 'standard';

    public const TYPE_COMBO = 'combo';

    public function isCombo(): bool
    {
        return (string) ($this->product_type ?? self::TYPE_STANDARD) === self::TYPE_COMBO;
    }
}
