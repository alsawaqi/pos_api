<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.2 — the device offline-sync ledger (shared pos_sync_events
 * table, owned by pos_admin's schema; blueprint §10.9).
 *
 * Append-only idempotency log: every state-mutating action a terminal
 * performs offline (order, payment, void, donation, expense, restock,
 * shift) is one row keyed by a client-generated UUID. The UNIQUE
 * `client_event_id` is what makes a replayed 4-hour-old batch settle
 * EXACTLY once — a re-pushed event collides and re-returns its first
 * ACK instead of producing a second effect.
 *
 * Unlike the read-only 8.1 catalogue models, pos_api WRITES this table,
 * so it carries an explicit fillable set. It has NO created_at/updated_at
 * (the migration tracks server_received_at/processed_at instead), hence
 * $timestamps = false.
 *
 * 8.2 only INGESTS (ack_status = received). Domain processing — turning a
 * received event into an order/payment/etc. and stamping it processed or
 * failed with a result_json — lands in 8.3+.
 */
#[Fillable([
    'client_event_id',
    'device_id',
    'event_type',
    'payload_json',
    'client_timestamp',
    'server_received_at',
    'processed_at',
    'ack_status',
    'result_json',
])]
class SyncEvent extends Model
{
    /** Stamped by server_received_at / processed_at, not created_at/updated_at. */
    public $timestamps = false;

    protected $table = 'pos_sync_events';

    /** Freshly ingested, awaiting domain processing (8.3+). */
    public const STATUS_RECEIVED = 'received';

    /** Domain handler ran and committed its effect. */
    public const STATUS_PROCESSED = 'processed';

    /** Domain handler ran and rejected the event. */
    public const STATUS_FAILED = 'failed';

    /**
     * The event taxonomy the ledger accepts (blueprint §10.9). The ledger
     * rejects anything outside this set so junk never lands in it; extend
     * here as later sub-phases add handlers.
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'order.create',
        // Phase C2 — mirror a held (parked) order server-side so it survives
        // a device wipe and is visible to the branch's other terminals.
        'order.hold',
        // Device-to-device transfer: park an unpaid order (held mirror)
        // addressed to another device in the same branch. Upserts through the
        // exact order.hold path, then stamps the transfer target.
        'order.transfer',
        'order.pay',
        // P-G7 — close a no-tender delivery-provider order as pending
        // verification (consumes inventory; money waits for the merchant's
        // statement reconciliation on the Deliveries page).
        'order.deliver',
        'order.void',
        'donation.record',
        'expense.log',
        'restock.request',
        // Phase A (Additions §2.8) — day-end physical stock count.
        'stock.count',
        // Product wastage: cooked or bought-in products wasted at the branch
        // (the product-units parallel of the stock.count shortfall waste).
        'product.waste',
        'shift.open',
        'shift.close',
        // Phase 3 — advertising slider play-time telemetry from the customer
        // screen: one event per slide shown (→ pos_marketing_impressions).
        'slider.display',
        // Reserved no-op: ingested + ACKed but has NO domain handler, so it
        // settles as 'received'. Lets the ingestion pipe be exercised in
        // isolation and stays a stable placeholder as handlers are added
        // (it replaced donation.record once that got a handler).
        'sync.noop',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'result_json' => 'array',
            'client_timestamp' => 'datetime',
            'server_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
