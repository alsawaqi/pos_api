<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One durable branch-local print claim; failure is explicitly reclaimable. */
final class KitchenTicket extends Model
{
    public const RESULT_RETIRED_LOCAL_UNKNOWN = 'retired_unknown';

    protected $table = 'pos_kitchen_tickets';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'printed_at' => 'datetime'];
    }
}
