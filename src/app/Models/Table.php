<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_tables` table (dine-in tables).
 * Served in the device config bundle (Phase 8.1); never written here.
 */
class Table extends Model
{
    use SoftDeletes;

    protected $table = 'pos_tables';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'min_party' => 'integer',
            'max_party' => 'integer',
            'display_order' => 'integer',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
