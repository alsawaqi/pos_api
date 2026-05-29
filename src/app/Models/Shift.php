<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.5 — a cashier shift on a device (pos_shifts, blueprint §10.8).
 *
 * opening_cash is the float at open; at close the handler computes
 * expected_cash (opening + net cash taken during the shift window) and
 * variance (closing − expected) — a negative variance means the drawer is
 * short. Writable here (the device opens/closes shifts via sync events).
 */
class Shift extends Model
{
    protected $table = 'pos_shifts';

    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:3',
            'closing_cash' => 'decimal:3',
            'expected_cash' => 'decimal:3',
            'variance' => 'decimal:3',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
