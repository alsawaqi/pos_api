<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.4 — append-only loyalty ledger (pos_loyalty_transactions,
 * blueprint §10.6). One row per earn/redeem/adjust/expire; carries
 * signed points/stamps deltas plus the post-application
 * balance_after_* snapshots so the history view never re-sums and
 * account⇄ledger drift is caught instantly.
 *
 * Like {@see SyncEvent} / {@see StockMovement}, the table has only
 * created_at + occurred_at (no updated_at), so timestamps are off.
 * order_id links an earn to the triggering sale; recorded_by_user_id
 * is null for POS-driven writes (set only for portal adjustments).
 */
class LoyaltyTransaction extends Model
{
    public $timestamps = false;

    protected $table = 'pos_loyalty_transactions';

    protected $guarded = [];

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_ADJUST = 'adjust';

    public const TYPE_EXPIRE = 'expire';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points_delta' => 'integer',
            'stamps_delta' => 'integer',
            'balance_after_points' => 'integer',
            'balance_after_stamps' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
