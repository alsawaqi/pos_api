<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase A (Additions §2.8) — day-end stock count header
 * (pos_stock_counts; schema owned by pos_admin's 2026_07_01
 * migration). Written here when a DEVICE submits the count via the
 * stock.count sync event; the merchant portal writes its own counts
 * through pos_merchant.
 */
class StockCount extends Model
{
    protected $table = 'pos_stock_counts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
