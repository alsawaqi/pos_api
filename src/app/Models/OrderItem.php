<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8.3 — order line item (pos_order_items, blueprint §10.8).
 *
 * recipe_snapshot_json freezes the product's recipe at order-create time
 * (a list of {ingredient_id, qty, unit, unit_cost}) so the pay-time stock
 * deduction — and later COGS reporting — is immune to recipe edits. NULL
 * for pre-made goods with no recipe (no inventory deduction).
 */
class OrderItem extends Model
{
    protected $table = 'pos_order_items';

    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_VOID = 'void';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:3',
            'line_discount' => 'decimal:3',
            'line_total' => 'decimal:3',
            'recipe_snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<OrderItemAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(OrderItemAddon::class, 'order_item_id');
    }
}
