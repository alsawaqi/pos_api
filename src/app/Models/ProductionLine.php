<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G1 — one ingredient line of a kitchen production batch
 * (pos_production_lines; schema owned by pos_admin 2026_07_14_010000).
 *
 * is_extra=false rows are the LOCKED recipe x batch quantity; is_extra=true
 * rows are the extras the chef explicitly declared. Stored separately for
 * the merchant's kitchen variance view. Quantities are positive — the
 * signed ledger truth lives in pos_stock_movements.
 */
class ProductionLine extends Model
{
    protected $table = 'pos_production_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_extra' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Production, $this>
     */
    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
