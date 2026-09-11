<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8.3 — order header (shared pos_orders table, owned by pos_admin;
 * blueprint §10.8). The transactional spine: the device's order.create
 * sync event lands here, order.pay flips it to paid, order.void cancels it.
 *
 * Writable (unlike the 8.1 read-only catalogue models). Money columns are
 * decimal(12,3) OMR; the handlers convert from wire baisas via
 * {@see Money}. Invariant: subtotal − discount_total +
 * tax_total == grand_total.
 */
class Order extends Model
{
    protected $table = 'pos_orders';

    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_HELD = 'held';

    public const STATUS_KITCHEN = 'kitchen';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    // P-G7 — a no-tender delivery-provider order awaiting the provider's
    // statement. The merchant's Deliveries page confirms/adjusts it, which
    // flips it to paid (revenue dated at confirmation). Inventory is consumed
    // at intake (the food left the shop); money effects wait for confirmation.
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    // Archived unpaid source of an explicit manager-reviewed combine. Not a
    // paid sale, void or refund; its original frozen children remain as audit.
    public const STATUS_COMBINED = 'combined';

    /** @var list<string> */
    public const TYPES = ['quick', 'dine_in', 'to_go', 'delivery', 'car'];

    public const SOURCE_QR_WEB = 'qr_web';

    /** @var list<string> */
    public const SOURCES = ['main_pos', 'handheld', 'customer_tablet', self::SOURCE_QR_WEB];

    public const CHARGE_OUTCOME_APPROVED = 'approved';

    public const CHARGE_OUTCOME_DECLINED = 'declined';

    public const CHARGE_OUTCOME_CANCELLED = 'cancelled';

    public const CHARGE_OUTCOME_LAPSED = 'lapsed';

    public const CHARGE_OUTCOME_UNCERTAIN = 'uncertain';

    /** @var list<string> */
    public const CHARGE_OUTCOMES = [
        self::CHARGE_OUTCOME_APPROVED,
        self::CHARGE_OUTCOME_DECLINED,
        self::CHARGE_OUTCOME_CANCELLED,
        self::CHARGE_OUTCOME_LAPSED,
        self::CHARGE_OUTCOME_UNCERTAIN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:3',
            'discount_total' => 'decimal:3',
            'tax_total' => 'decimal:3',
            'grand_total' => 'decimal:3',
            'charge_amount_baisas' => 'integer',
            'charge_roundup_amount_baisas' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'charge_claimed_at' => 'datetime',
            'charge_deadline_at' => 'datetime',
            // Device-to-device order transfer.
            'transferred_at' => 'datetime',
            // P-G7 — delivery-provider lifecycle.
            'delivery_commission_percent' => 'decimal:2',
            'delivery_expected_payout' => 'decimal:3',
            'delivery_received_amount' => 'decimal:3',
            'delivery_variance' => 'decimal:3',
            'delivery_punched_at' => 'datetime',
            'delivery_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The single canonical live-charge-claim predicate from QR-001 R3 section 5.
     * It asserts awaiting_payment; callers negating LC must still assert that status positively.
     */
    public function scopeWithLiveClaim(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_AWAITING_PAYMENT)
            ->whereNotNull('charge_claimed_at')
            ->where(function (Builder $claim) use ($at): void {
                $claim
                    ->where(function (Builder $inFlight) use ($at): void {
                        $inFlight
                            ->whereNull('charge_outcome')
                            ->where('charge_deadline_at', '>', $at);
                    })
                    ->orWhereIn('charge_outcome', [
                        self::CHARGE_OUTCOME_UNCERTAIN,
                        self::CHARGE_OUTCOME_APPROVED,
                    ]);
            });
    }

    /**
     * The fail-closed predicate for starting a new automatic charge.
     *
     * Only a never-claimed order with no outcome, or a claim the station
     * affirmatively reported as declined/cancelled, is safe to offer again.
     * An expired unresolved claim and lapsed/uncertain/approved outcomes match
     * neither scope: they require an attended recovery path instead.
     */
    public function scopeWithoutLiveClaim(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query
            ->where('status', self::STATUS_AWAITING_PAYMENT)
            ->where(function (Builder $claim): void {
                $claim
                    ->where(function (Builder $neverClaimed): void {
                        $neverClaimed
                            ->whereNull('charge_claimed_at')
                            ->whereNull('charge_outcome');
                    })
                    ->orWhereIn('charge_outcome', [
                        self::CHARGE_OUTCOME_DECLINED,
                        self::CHARGE_OUTCOME_CANCELLED,
                    ]);
            });
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<TableSession, $this> */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /**
     * @return HasMany<OrderComp, $this>
     */
    public function comps(): HasMany
    {
        return $this->hasMany(OrderComp::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<QrOrderRound, $this>
     */
    public function qrRounds(): HasMany
    {
        return $this->hasMany(QrOrderRound::class, 'order_id');
    }
}
