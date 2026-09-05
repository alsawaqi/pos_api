<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The seating survives the station and its short-lived QR credential. */
final class TableSession extends Model
{
    protected $table = 'pos_table_sessions';

    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_BILLING = 'billing';

    public const STATUS_CLOSING = 'closing';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_MERGED = 'merged';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> Matches the schema's partial unique index. */
    public const LIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_BILLING, self::STATUS_CLOSING];

    public const ORIGIN_STATION = 'station';

    public const ORIGIN_STAFF_TILL = 'staff_till';

    public const ORIGIN_STAFF_HANDHELD = 'staff_handheld';

    public const ORIGIN_TABLE_CARD = 'table_card';

    public const CLOSE_PAID = 'paid';

    public const CLOSE_VOIDED = 'voided';

    public const CLOSE_CLEARED = 'cleared';

    public const CLOSE_ABANDONED = 'abandoned';

    public const CLOSE_EXPIRED = 'expired';

    public const CLOSE_STAFF_CLOSE = 'staff_close';

    public const CLOSE_MERGED = 'merged';

    public const CLOSE_ATTACHED = 'attached';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'billing_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Table, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<QrSession, $this> */
    public function qrSessions(): HasMany
    {
        return $this->hasMany(QrSession::class, 'table_session_id');
    }

    /** @return HasMany<QrOrderRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(QrOrderRound::class, 'table_session_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function openedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'opened_by_device_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function closedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'closed_by_device_id');
    }
}
