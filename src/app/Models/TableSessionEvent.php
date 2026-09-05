<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only lifecycle journal; its id is the branch feed cursor. */
final class TableSessionEvent extends Model
{
    protected $table = 'pos_table_session_events';

    protected $guarded = [];

    public $timestamps = false;

    /** @var list<string> */
    public const EVENT_TYPES = [
        'opened', 'attached', 'round_appended', 'round_pending', 'round_resolved',
        'billing', 'reopened', 'closed', 'expired', 'merged', 'moved', 'joined',
        'needs_review', 'customer_order_arrived', 'sent_to_counter',
        'print_claimed', 'print_result',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
