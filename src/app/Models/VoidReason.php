<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase B — read-mostly mirror of the shared `pos_void_reasons` table
 * (CRUD lives in pos_merchant; schema in pos_admin's 2026_07_02
 * migration). The device config ships the active set; order.void
 * resolves the picked reason here. affects_inventory = TRUE means
 * the food was actually made, so voiding KEEPS the recipe
 * ingredients consumed (no inventory reverse).
 */
class VoidReason extends Model
{
    use SoftDeletes;

    protected $table = 'pos_void_reasons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affects_inventory' => 'boolean',
            'requires_manager' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
