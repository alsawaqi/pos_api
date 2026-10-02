<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
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
            'units_per_piece' => 'decimal:4',
            'allow_fractional_pieces' => 'boolean',
            'default_unit_cost' => ScaledDecimal::class.':3,6',
            'min_stock_threshold' => ScaledDecimal::class.':3,4',
        ];
    }
}
