<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_addon_groups` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 */
class AddOnGroup extends Model
{
    use SoftDeletes;

    protected $table = 'pos_addon_groups';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
