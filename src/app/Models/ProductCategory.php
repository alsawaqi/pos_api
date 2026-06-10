<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_product_categories` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 */
class ProductCategory extends Model
{
    use SoftDeletes;

    protected $table = 'pos_product_categories';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            // Phase D2 — §5.5.1 branch availability: NULL = all branches,
            // else an array of pos_branches ids.
            'branch_availability_json' => 'array',
        ];
    }
}
