<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_floors` table (dine-in floor plan).
 * Served in the device config bundle (Phase 8.1); never written here.
 */
class Floor extends Model
{
    use SoftDeletes;

    protected $table = 'pos_floors';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }
}
