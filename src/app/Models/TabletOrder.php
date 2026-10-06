<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH-P6 — one customer tablet submit (pos_admin 2026_10_06_120001): a
 * Quick / To go order, or a dine-in round on a table bill. It carries what the
 * order itself has no column for: the tablet's submit key, the customer's
 * points request, who took it ("Taken by") and who sent it to the kitchen.
 */
final class TabletOrder extends Model
{
    protected $table = 'pos_tablet_orders';

    protected $guarded = [];

    public const TYPES = ['dine_in', 'quick', 'to_go'];

    public const REDEEM_REQUESTED = 'requested';

    public const REDEEM_APPROVED = 'approved';

    public const REDEEM_REJECTED = 'rejected';

    /** Fix order 1 (F-2) — approved, but its bill slot was later cleared. */
    public const REDEEM_SUPERSEDED = 'superseded';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kitchen_lines' => 'array',
            'ready_in_minutes' => 'integer',
            'subtotal_baisas' => 'integer',
            'tax_baisas' => 'integer',
            'total_baisas' => 'integer',
            'redeem_blocks' => 'integer',
            'redeem_units' => 'integer',
            'redeem_amount_baisas' => 'integer',
            'redeem_resolved_at' => 'datetime',
            'taken_at' => 'datetime',
            'sent_to_kitchen_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<QrOrderRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(QrOrderRound::class, 'round_id');
    }

    public function isDineIn(): bool
    {
        return $this->order_type === 'dine_in';
    }

    /**
     * LAUNCH-P6 fix order 5 (F-18) — whether a tablet order on this order
     * still waits for staff to answer its points request (a dine-in round
     * staff rejected no longer counts: its request can never be answered).
     */
    public static function hasOpenRedeemRequest(int $orderId): bool
    {
        return self::query()->where('order_id', $orderId)->where('redeem_status', self::REDEEM_REQUESTED)
            ->where(fn ($round) => $round->whereNull('round_id')->orWhereNotExists(fn ($rejected) => $rejected->selectRaw('1')
                ->from('pos_qr_order_rounds as redeem_round')->whereColumn('redeem_round.id', 'pos_tablet_orders.round_id')
                ->where('redeem_round.status', QrOrderRound::STATUS_REJECTED)))
            ->exists();
    }

    /**
     * "Quick order" and "To go" show the number part of the order's temporary
     * reference, large (tester call 6): T-1006-027 → "27". Dine in has none.
     */
    public static function orderNumber(?string $tempReference): ?string
    {
        if ($tempReference === null || ! preg_match('/([0-9]+)$/', $tempReference, $m)) {
            return null;
        }
        $number = ltrim($m[1], '0');

        return $number === '' ? '0' : $number;
    }
}
