<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH-P5 — one worked period of a staff member (pos_staff_attendance,
 * owned by pos_admin's schema). Devices write it through the staff.clock_in /
 * staff.clock_out sync events; the portal's Hours report edits it.
 */
class StaffAttendance extends Model
{
    protected $table = 'pos_staff_attendance';

    protected $guarded = [];

    public const SOURCE_DEVICE = 'device';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'flags' => 'array',
        ];
    }
}
