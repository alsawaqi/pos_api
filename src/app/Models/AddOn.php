<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_addons` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 * `price_delta` is decimal(12,3) OMR — converted to baisas on the wire.
 */
class AddOn extends Model
{
    use SoftDeletes;

    protected $table = 'pos_addons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:3',
            'ingredient_qty' => 'decimal:3',
            'display_order' => 'integer',
        ];
    }
}
