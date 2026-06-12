<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.3 — per-line add-on selection (pos_order_item_addons, §10.8).
 *
 * ingredient_snapshot_json freezes the add-on's ingredient mapping at
 * order-create time ({ingredient_id, qty, unit, unit_cost}) so an "extra
 * shot" deducts its beans on sale. NULL when the add-on tracks no ingredient.
 */
class OrderItemAddon extends Model
{
    protected $table = 'pos_order_item_addons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_snapshot' => 'decimal:3',
            'ingredient_snapshot_json' => 'array',
            // P-G3 — product-as-add-on freeze: {product_id, stock_mode,
            // recipe} drives consumption by the product's type at pay.
            'product_snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
