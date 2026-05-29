<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.4 — a customer's loyalty balance under one rule
 * (pos_loyalty_accounts, blueprint §10.6). One row per
 * (customer, rule); stamp_count / point_balance are the
 * denormalised running balances kept in lock-step with the
 * append-only pos_loyalty_transactions ledger.
 *
 * Writable here (the device sale pipeline earns into it). The
 * 8.1 read-only catalogue models don't include this — it's
 * volatile balance data, intentionally NOT shipped in the config
 * bundle.
 */
class LoyaltyAccount extends Model
{
    protected $table = 'pos_loyalty_accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamp_count' => 'integer',
            'point_balance' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }
}
