<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One append-only, server-priced dine-in submission. */
final class QrOrderRound extends Model
{
    protected $table = 'pos_qr_order_rounds';

    protected $guarded = [];

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING_CONFIRMATION,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priced_lines' => 'array',
            'confirm_payload' => 'array',
            'accepted_seq' => 'integer',
            'subtotal_baisas' => 'integer',
            'tax_baisas' => 'integer',
            'total_baisas' => 'integer',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QrSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(QrSession::class, 'qr_session_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<TableSession, $this> */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function resolvedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'resolved_by_device_id');
    }
}
