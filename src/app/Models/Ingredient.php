<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_ingredients` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 */
class Ingredient extends Model
{
    use SoftDeletes;

    protected $table = 'pos_ingredients';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_unit_cost' => 'decimal:3',
            'min_stock_threshold' => 'decimal:3',
        ];
    }
}
