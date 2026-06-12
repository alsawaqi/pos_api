<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P-G6 — a portal → device staff announcement (channel 1). Composed in
 * pos_merchant; this app SERVES it in the /device/config staff_messages
 * slice and writes the read receipts. target_type = staff | branch |
 * company. Soft delete = portal retraction (the id surfaces in the config
 * delta's deleted map so devices purge it).
 *
 * Schema owned by pos_admin (2026_07_19_000000_create_pos_messaging_tables).
 */
class StaffMessage extends Model
{
    use SoftDeletes;

    public const TARGET_STAFF = 'staff';

    public const TARGET_BRANCH = 'branch';

    public const TARGET_COMPANY = 'company';

    protected $table = 'pos_staff_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StaffMessageRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(StaffMessageRead::class, 'staff_message_id');
    }
}
