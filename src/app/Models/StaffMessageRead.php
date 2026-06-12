<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G6 — a read receipt: staff member X saw announcement Y ("sent is not
 * the same as seen"). One row per (message, staff); device_id records the
 * till that marked it. Writing a receipt touch()es the parent message so
 * the updated read-set resurfaces in other devices' config deltas.
 */
class StaffMessageRead extends Model
{
    protected $table = 'pos_staff_message_reads';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }
}
