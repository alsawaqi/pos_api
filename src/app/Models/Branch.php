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
            'location_check_enabled' => 'boolean',
            'location_check_off_since' => 'datetime',
            'location_check_off_windows' => 'array',
        ];
    }

    /**
     * LAUNCH-P5 add-on — the branch "Location check" switch is ON (the
     * default; a row from before the column counts as on). Off: staff may log
     * in and sell from any location at this branch.
     */
    public function locationCheckEnabled(): bool
    {
        return $this->getAttribute('location_check_enabled') !== false;
    }

    /** The switch of a branch by id (on when there is no such branch). */
    public static function locationCheckEnabledFor(?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }
        $value = self::query()->whereKey($branchId)->value('location_check_enabled');

        return $value === null || (bool) $value;
    }
}
