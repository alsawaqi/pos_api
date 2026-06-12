<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
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

    public const STATUS_PAID = 'paid';

    // P-G7 — a no-tender delivery-provider order awaiting the provider's
    // statement. The merchant's Deliveries page confirms/adjusts it, which
    // flips it to paid (revenue dated at confirmation). Inventory is consumed
    // at intake (the food left the shop); money effects wait for confirmation.
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    /** @var list<string> */
    public const TYPES = ['quick', 'dine_in', 'to_go', 'delivery', 'car'];

    /** @var list<string> */
    public const SOURCES = ['main_pos', 'handheld', 'customer_tablet'];

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
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
