<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\Sync\ApplyLoyaltyEarnAction;
use App\Actions\Device\Sync\ApplyLoyaltyRedeemAction;
use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\RecordSaleCommissionAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.3 — processes an `order.pay` sync event: records the tender(s),
 * flips the order to paid, and deducts inventory (§16 "inventory deducts at
 * payment completion").
 *
 * Split tender = several payment rows. A card payment the cashier bypassed
 * on NFC-timeout arrives status=pending_reconciliation and is flagged for
 * the admin reconciliation queue. The order is resolved scoped to the
 * device's company + branch, so a device can't pay another tenant's /
 * branch's order. Invariant: Σ(tendered) == grand_total.
 */
class PayOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
        private readonly ApplyLoyaltyEarnAction $loyalty,
        private readonly ApplyLoyaltyRedeemAction $loyaltyRedeem,
        private readonly GeofenceGuard $geofence,
        private readonly RecordSaleCommissionAction $saleCommission,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;
        $payments = $payload['payments'] ?? null;

        if (! is_string($orderUuid) || ! is_array($payments) || $payments === []) {
            throw new RuntimeException('invalid order.pay payload: order_uuid + non-empty payments required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for payment: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_PAID) {
            throw new RuntimeException('order already paid: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID) {
            throw new RuntimeException('cannot pay a voided order: '.$orderUuid);
        }

        // Geofence: a device must be inside its branch fence to take payment
        // (fail-closed at a fenced branch), so it can't be carried away and
        // keep settling sales.
        $this->enforceGeofence($device, $payload);

        $capturedAt = isset($payload['paid_at']) ? Carbon::parse((string) $payload['paid_at']) : now();
        $loyaltyRuleIds = $this->earnRuleIds($payload);
        $loyaltyRedeem = is_array($payload['loyalty_redeem'] ?? null) ? $payload['loyalty_redeem'] : null;

        return DB::transaction(function () use ($order, $device, $event, $payments, $capturedAt, $loyaltyRuleIds, $loyaltyRedeem): array {
            $paymentIds = [];
            $tenderedBaisas = 0;
            // Card-paid portion of this sale — drives the acquirer (bank)
            // commission slice, which is charged on card money only.
            $cardBaisas = 0;

            foreach ($payments as $tender) {
                if (! isset($tender['method'], $tender['amount_baisas']) || ! in_array($tender['method'], Payment::METHODS, true)) {
                    throw new RuntimeException('invalid payment tender in order.pay');
                }

                $status = $tender['status'] ?? Payment::STATUS_SUCCESS;
                $payment = Payment::create([
                    'uuid' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'method' => $tender['method'],
                    'amount' => Money::toOmr((int) $tender['amount_baisas']),
                    'change_given' => isset($tender['change_given_baisas']) ? Money::toOmr((int) $tender['change_given_baisas']) : null,
                    'softpos_reference' => $tender['softpos_reference'] ?? null,
                    'softpos_auth_code' => $tender['softpos_auth_code'] ?? null,
                    'status' => $status,
                    'pending_reconciliation' => $status === Payment::STATUS_PENDING_RECONCILIATION,
                    // Snapshot the device's acquirer facts onto the payment row so
                    // the admin Bank Reconciliation Queue matches on the payment's
                    // own columns instead of a device join. bank_response is the raw
                    // Soft POS verdict (card tenders only); cash carries none.
                    'device_id' => $device->id,
                    'terminal_id' => $device->terminal_id,
                    'bank_id' => $device->bank_id,
                    'bank_response' => is_array($tender['bank_response'] ?? null) ? $tender['bank_response'] : null,
                    'captured_at' => $capturedAt,
                ]);

                $paymentIds[] = (int) $payment->id;
                $tenderedBaisas += (int) $tender['amount_baisas'];

                if ($tender['method'] === Payment::METHOD_CARD && $status !== Payment::STATUS_FAILED) {
                    $cardBaisas += (int) $tender['amount_baisas'];
                }
            }

            $grandBaisas = Money::toBaisas($order->grand_total);
            if (abs($tenderedBaisas - $grandBaisas) > 1) {
                throw new RuntimeException('payment total mismatch: tendered '.$tenderedBaisas.' baisas vs grand_total '.$grandBaisas);
            }

            $order->update([
                'status' => Order::STATUS_PAID,
                'closed_at' => $capturedAt,
            ]);

            $movements = $this->inventory->consume($order);

            // Per-sale commission split: apply the merchant's profile and
            // record the platform/bank/other/merchant breakdown. No active
            // profile ⇒ no rows (merchant keeps 100%). Snapshots the
            // percents so later profile edits never rewrite this sale.
            $saleCommissionIds = $this->saleCommission->record(
                $order,
                $device,
                $cardBaisas,
                $paymentIds[0] ?? null,
                $event->client_event_id,
            );

            // Loyalty earn (server-authoritative, §9.1.6): accrue under EVERY
            // rule the cashier named for a known customer. A merchant can run
            // several earn programs at once (e.g. a stamp card AND points), so
            // each applicable rule credits — not just the first (v2 #3).
            $loyaltyTxnIds = [];
            foreach ($loyaltyRuleIds as $ruleId) {
                $txn = $this->loyalty->apply($order, $ruleId);
                if ($txn !== null) {
                    $loyaltyTxnIds[] = (int) $txn->id;
                }
            }

            // Loyalty redemption: record the points/stamps SPENT (their value
            // is already on the order as a snapshot discount).
            $redeemTxn = $loyaltyRedeem !== null && isset($loyaltyRedeem['rule_id'])
                ? $this->loyaltyRedeem->apply(
                    $order,
                    (int) $loyaltyRedeem['rule_id'],
                    (int) ($loyaltyRedeem['points'] ?? 0),
                    (int) ($loyaltyRedeem['stamps'] ?? 0),
                )
                : null;

            return [
                'order_id' => (int) $order->id,
                'status' => 'paid',
                'payment_ids' => $paymentIds,
                'movements' => $movements,
                'sale_commission_ids' => $saleCommissionIds,
                // First id kept for back-compat; the full set under _ids.
                'loyalty_transaction_id' => $loyaltyTxnIds[0] ?? null,
                'loyalty_transaction_ids' => $loyaltyTxnIds,
                'loyalty_redeem_transaction_id' => $redeemTxn?->id,
            ];
        });
    }

    /**
     * The loyalty EARN rule ids named on the pay event. Accepts the v2 #3
     * `loyalty_rule_ids` (array — earn under several programs at once) or the
     * legacy single `loyalty_rule_id`. De-duped, positive ints only.
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function earnRuleIds(array $payload): array
    {
        $raw = [];
        if (is_array($payload['loyalty_rule_ids'] ?? null)) {
            $raw = $payload['loyalty_rule_ids'];
        } elseif (isset($payload['loyalty_rule_id'])) {
            $raw = [$payload['loyalty_rule_id']];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $raw),
            static fn (int $v): bool => $v > 0,
        )));
    }

    /**
     * Fail-closed geofence for payment: at a fenced branch the device must
     * supply a GPS fix inside the fence, else the payment is rejected.
     *
     * @param  array<string, mixed>  $payload
     */
    private function enforceGeofence(Device $device, array $payload): void
    {
        $branch = Branch::find($device->branch_id);
        if ($branch === null || ! $this->geofence->isFenced($branch)) {
            return;
        }

        $gps = $payload['gps'] ?? null;
        if (! is_array($gps) || ! isset($gps['lat'], $gps['lng'])) {
            throw new RuntimeException('payment rejected: a GPS fix is required at this geofenced branch');
        }

        $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
    }
}
