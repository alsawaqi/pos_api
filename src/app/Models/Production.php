<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P-G1 — a kitchen production batch (pos_productions; schema owned by
 * pos_admin 2026_07_14_010000).
 *
 * "Cooked" products are made ahead of sale: the recipe ingredients are
 * deducted from pos_branch_stock when the batch STARTS (they physically
 * left the shelf — a parallel batch cannot claim them), and the finished
 * pieces land in pos_branch_product.stock_qty when it FINISHES. A
 * manager-PIN-gated CANCEL returns the ingredients.
 *
 * pos_api is the ONLY writer (the device Kitchen screen, online-only);
 * the merchant portal reads the rows for its Production history page.
 */
class Production extends Model
{
    protected $table = 'pos_productions';

    protected $guarded = [];

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class);
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function startedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'started_by_staff_id');
    }
}
