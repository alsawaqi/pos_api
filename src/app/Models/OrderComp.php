<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase B — one comp granted on an order (pos_order_comps; mirrors
 * pos_order_discounts). order_item_id NULL = whole-order comp.
 * Written by CreateOrderHandler from the order.create payload;
 * inventory deducts AS IF SOLD and the value reduces what the
 * customer pays while being reported separately from discounts.
 */
class OrderComp extends Model
{
    protected $table = 'pos_order_comps';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'applied_at' => 'datetime',
        ];
    }
}
