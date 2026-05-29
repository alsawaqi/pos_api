<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.10 — discount-application record (blueprint §5.11.7 + §9.1.6).
 *
 * Written by the order.create sync handler: one row per discount applied to
 * an order, recording which rule (or a manual ad-hoc discount, discount_id
 * NULL) granted how much. `order_item_id` NULL = an order-level discount.
 * The rule name/type are snapshotted so a later rename or soft-delete still
 * reads correctly in the merchant's by-rule Discount Report.
 *
 * Writable here (pos_api owns the sale pipeline); money at rest is decimal
 * OMR — the wire carries integer baisas, converted via {@see Money}.
 */
class OrderDiscount extends Model
{
    protected $table = 'pos_order_discounts';

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
