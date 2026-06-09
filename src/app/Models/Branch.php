<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared, pos_admin-owned `pos_branches` table.
 *
 * pos_api serves it in the device config bundle (Phase 8.1) and never
 * writes it. Unguarded only so tests can seed it freely; there are no
 * write paths in this app.
 */
class Branch extends Model
{
    use SoftDeletes;

    protected $table = 'pos_branches';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius_m' => 'integer',
            'opening_hours_json' => 'array',
            'settings' => 'array',
            'receipt_template' => 'array',
        ];
    }
}
