<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.3 — payment tender (pos_payments, blueprint §10.8 + §16 Soft POS).
 *
 * One row per tender; a split payment is several rows. A card payment that
 * the cashier bypassed on NFC-timeout lands as status=pending_reconciliation
 * with pending_reconciliation=true — that's the row the admin Bank
 * Reconciliation Queue (§4.6) matches against the bank settlement file.
 */
class Payment extends Model
{
    protected $table = 'pos_payments';

    protected $guarded = [];

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_SPLIT_PART = 'split_part';

    public const METHOD_LOYALTY = 'loyalty';

    public const METHOD_GIFT = 'gift';

    /** @var list<string> */
    public const METHODS = ['cash', 'card', 'split_part', 'loyalty', 'gift'];

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PENDING_RECONCILIATION = 'pending_reconciliation';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'change_given' => 'decimal:3',
            'pending_reconciliation' => 'boolean',
            'captured_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'roundup_amount' => 'decimal:3',
            'charity_transaction_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
