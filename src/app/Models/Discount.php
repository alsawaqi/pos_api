<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_discounts` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 * `amount` is decimal(12,3) — interpreted as OMR (→ baisas) when the
 * amount_type is `fixed`, or as a percentage when `percent`.
 */
class Discount extends Model
{
    use SoftDeletes;

    protected $table = 'pos_discounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'dayofweek_mask' => 'integer',
            'branch_scope_json' => 'array',
            'stackable' => 'boolean',
            'requires_manager_approval' => 'boolean',
            // P-F4: order-scope auto-application (always true for
            // product/category scopes — forced merchant-side).
            'auto_apply' => 'boolean',
        ];
    }
}
