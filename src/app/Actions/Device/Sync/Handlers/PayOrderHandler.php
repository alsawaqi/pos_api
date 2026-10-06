<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\AllocateOrderNumberAction;
use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\SnapshotCardSoftPos;
use App\Actions\Device\Sync\AfterSyncEventCommitHandler;
use App\Actions\Device\Sync\ApplyLoyaltyEarnAction;
use App\Actions\Device\Sync\ApplyLoyaltyRedeemAction;
use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\RecordSaleCommissionAction;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Actions\Qr\CloseDineInQrSessionAction;
use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Actions\Qr\PublicTableLoyalty;
use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Exceptions\InsufficientLoyaltyBalanceException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrSession;
use App\Models\RoundupDonation;
use App\Models\SyncEvent;
use App\Models\TableSession;
use App\Models\TabletOrder;
use App\Support\Money;
use App\Support\PaymentStaffSchema;
use App\Support\Staff\SaleAuthorizations;
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
class PayOrderHandler implements AfterSyncEventCommitHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
        private readonly ApplyLoyaltyEarnAction $loyalty,
        private readonly ApplyLoyaltyRedeemAction $loyaltyRedeem,
        private readonly GeofenceGuard $geofence,
        private readonly RecordSaleCommissionAction $saleCommission,
        private readonly DonationRecordHandler $donationRecord,
        private readonly QrChargeRecoveryGuard $qrChargeRecovery,
        private readonly CloseDineInQrSessionAction $closeDineInSession,
        private readonly AllocateOrderNumberAction $orderNumbers,
        private readonly CloseTableSessionForOrderAction $closeTableSession,
        private readonly SaleAuthorizations $sales,
        private readonly CloseShiftHandler $shifts,
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
        if ($order->status === Order::STATUS_PAID && ! $device->isPaymentStation()) {
            throw new RuntimeException('order already paid: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_PENDING_VERIFICATION && ! $device->isPaymentStation()) {
            // P-G7 — a no-tender delivery order settles via the merchant's
            // Deliveries reconciliation, never via a till tender.
            throw new RuntimeException('cannot pay a pending-verification delivery order: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID && ! $device->isPaymentStation()) {
            throw new RuntimeException('cannot pay a voided order: '.$orderUuid);
        }

        $capturedAt = isset($payload['paid_at']) ? Carbon::parse((string) $payload['paid_at']) : now();
        $loyaltyRedeem = is_array($payload['loyalty_redeem'] ?? null) ? $payload['loyalty_redeem'] : null;

        return DB::transaction(function () use ($order, $orderUuid, $device, $event, $payments, $capturedAt, $payload, $loyaltyRedeem): array {
            // Re-read + lock the order INSIDE the txn before consuming inventory.
            // The status guard above is unlocked, so two concurrent order.pay
            // events with DIFFERENT client_event_ids (not caught by the sync
            // de-dup) could both pass it and deduct stock twice. Locking the row
            // and re-checking the terminal status here serialises them: the
            // loser blocks until the winner commits, then sees STATUS_PAID and
            // throws — exactly one consume() ever runs.
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($order === null) {
                throw new RuntimeException('order already paid: '.$orderUuid);
            }

            if ($order->status === Order::STATUS_COMBINED) {
                throw new RuntimeException('combined order is not payable: '.$orderUuid);
            }

            // LAUNCH-P6 fix order 1 (F-3) — a tablet order is one the tablet wrote
            // (its pos_tablet_orders row), never one a device merely labelled.
            $isTablet = TabletOrder::query()->where('order_id', $order->id)->exists();
            // F-1 — who took the payment (order.pay staff_id), kept on the
            // payment when a staff member of this merchant (else NULL; the
            // payer check further down still flags an unknown one).
            $payerId = isset($payload['staff_id']) && PaymentStaffSchema::ready() && DB::table('pos_staff')
                ->where('company_id', $order->company_id)->where('id', (int) $payload['staff_id'])->exists()
                ? (int) $payload['staff_id'] : null;

            $claimAt = now();
            $claimIsLive = Order::query()
                ->whereKey($order->getKey())
                ->withLiveClaim($claimAt)
                ->exists();
            $claimHeldByDevice = $claimIsLive
                && $order->charge_device_id !== null
                && (int) $order->charge_device_id === (int) $device->getKey();
            $roundupBaisas = $device->isPaymentStation() && $claimHeldByDevice
                ? (int) ($order->charge_roundup_amount_baisas ?? 0)
                : 0;

            if ($device->isPaymentStation() && ! $claimHeldByDevice) {
                $evidence = $this->softPosEvidence($payments);

                if ($evidence !== null) {
                    $result = $this->recordLateAuthorizationOrphan(
                        $event,
                        $order,
                        $device,
                        $evidence,
                        $capturedAt,
                    );

                    if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                        $order->update([
                            'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
                        ]);
                    }

                    return $result;
                }
            }

            $isAmbiguousCounterRecovery = $this->qrChargeRecovery
                ->isAmbiguousCounterRecovery($order, $claimAt);
            if ($isAmbiguousCounterRecovery
                && ! $this->qrChargeRecovery->isAttendedDevice($device)) {
                throw new RuntimeException(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                );
            }

            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
                if ($order->status === Order::STATUS_PAID) {
                    throw new RuntimeException('order already paid: '.$orderUuid);
                }

                throw new RuntimeException('cannot pay a voided order: '.$orderUuid);
            }
            if ($order->status === Order::STATUS_PENDING_VERIFICATION) {
                throw new RuntimeException('cannot pay a pending-verification delivery order: '.$orderUuid);
            }
            if ($order->charge_outcome === Order::CHARGE_OUTCOME_UNCERTAIN
                && ! $isAmbiguousCounterRecovery) {
                throw new RuntimeException('cannot settle an uncertain charge outcome: '.$orderUuid);
            }
            if ($claimIsLive && ! $claimHeldByDevice) {
                throw new RuntimeException('live charge claim is held by another device: '.$orderUuid);
            }
            if ($device->isPaymentStation() && ! $claimHeldByDevice) {
                throw new RuntimeException('payment station must hold a live charge claim: '.$orderUuid);
            }
            if ($order->status === Order::STATUS_AWAITING_PAYMENT && ! $claimIsLive) {
                throw new RuntimeException('awaiting-payment order has no live charge claim: '.$orderUuid);
            }

            if ($device->isPaymentStation() && count($payments) !== 1) {
                throw new RuntimeException('payment station requires exactly one card tender');
            }
            foreach ($payments as $tender) {
                if (! is_array($tender)) {
                    throw new RuntimeException('invalid payment tender in order.pay');
                }
                if ($claimIsLive
                    && (! array_key_exists('amount_baisas', $tender)
                        || ! is_int($tender['amount_baisas']))) {
                    throw new RuntimeException('claimed charge requires an integer tender amount');
                }
                if ($device->isPaymentStation()
                    && ($tender['method'] ?? null) !== Payment::METHOD_CARD) {
                    throw new RuntimeException('payment station accepts card tenders only');
                }
                if ($claimIsLive
                    && ($tender['status'] ?? Payment::STATUS_SUCCESS) !== Payment::STATUS_SUCCESS) {
                    throw new RuntimeException('claimed charge requires a successful tender');
                }
            }

            // A live claim held by this device already paid the fail-closed
            // geofence cost before the tap. Every other path keeps the legacy
            // settle-time fence unchanged.
            if (! $claimHeldByDevice) {
                $this->enforceGeofence($device, $payload, $event);
            }

            $paymentIds = [];
            $softposMismatch = false;
            $tenderedBaisas = 0;
            // Card-paid portion of this sale — drives the acquirer (bank)
            // commission slice, which is charged on card money only.
            $cardBaisas = 0;
            // Phase D4 — gifted portion (blueprint §6.8: "zero charged to
            // customer"). Money never collected: excluded from the
            // commission base and, when the WHOLE order is gifted, from
            // loyalty earn.
            $giftBaisas = 0;
            // P-F7 — any tender the cashier force-recorded on an ambiguous
            // Soft POS charge (NFC timeout) lands pending_reconciliation:
            // the money is NOT yet confirmed, so the sale's downstream
            // money effects must wait for the admin's approval.
            $hasPendingTender = false;

            foreach ($payments as $tender) {
                if (! isset($tender['method'], $tender['amount_baisas']) || ! in_array($tender['method'], Payment::METHODS, true)) {
                    throw new RuntimeException('invalid payment tender in order.pay');
                }

                $status = $tender['status'] ?? Payment::STATUS_SUCCESS;
                $softpos = app(SnapshotCardSoftPos::class)->handle($device, $tender);
                $softposMismatch = $softposMismatch || ($softpos['softpos_mismatch'] ?? false);
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
                ] + (PaymentStaffSchema::ready() ? ['staff_id' => $payerId] : []) + $softpos);

                $paymentIds[] = (int) $payment->id;
                $tenderedBaisas += (int) $tender['amount_baisas'];

                if ($status === Payment::STATUS_PENDING_RECONCILIATION) {
                    $hasPendingTender = true;
                }

                // P-F5 — ONLY the 'card' method (our Soft POS) accumulates
                // into the bank-commission base. A 'bank_pos' tender is money
                // taken on the BANK'S OWN standalone terminal: the bank
                // already earned its acquirer fee on its own rails, so it
                // gets NO slice here and the merchant keeps that share
                // (bank_pos otherwise flows exactly like cash).
                if ($tender['method'] === Payment::METHOD_CARD && $status !== Payment::STATUS_FAILED) {
                    $cardBaisas += (int) $tender['amount_baisas'];
                }
                if ($tender['method'] === Payment::METHOD_GIFT && $status !== Payment::STATUS_FAILED) {
                    $giftBaisas += (int) $tender['amount_baisas'];
                }
            }

            $grandBaisas = Money::toBaisas($order->grand_total);
            if ($claimIsLive) {
                $frozenBaisas = (int) $order->charge_amount_baisas;
                if ($tenderedBaisas !== $frozenBaisas) {
                    throw new RuntimeException('payment total mismatch: tendered '.$tenderedBaisas.' baisas vs charge_amount_baisas '.$frozenBaisas);
                }
            } elseif (abs($tenderedBaisas - $grandBaisas) > 1) {
                throw new RuntimeException('payment total mismatch: tendered '.$tenderedBaisas.' baisas vs grand_total '.$grandBaisas);
            }

            $orderUpdate = [
                'status' => Order::STATUS_PAID,
                'closed_at' => $capturedAt,
            ];
            if ($claimIsLive) {
                $orderUpdate['charge_outcome'] = Order::CHARGE_OUTCOME_APPROVED;
            }
            if (trim((string) $order->receipt_number) === ''
                && ($order->source === Order::SOURCE_QR_WEB || $isTablet || $order->table_session_id !== null)) {
                // LAUNCH-P6 — a customer tablet order's receipt number is the server's too.
                $allocation = $this->orderNumbers->handle($device);
                if ($allocation !== null) {
                    $orderUpdate['receipt_number'] = $allocation['formatted'];
                }
            }
            $order->update($orderUpdate);
            $dineInSessionClosed = $this->closeDineInSession->handle($order, $capturedAt);
            $this->closeTableSession->handle($order, $capturedAt, TableSession::CLOSE_PAID, (int) $device->getKey());
            if ($order->qr_session_id !== null && ! $dineInSessionClosed) {
                DB::table('pos_qr_sessions')
                    ->where('id', (int) $order->qr_session_id)
                    ->where('company_id', (int) $order->company_id)
                    ->where('status', QrSession::STATUS_ORDERED)
                    ->update([
                        'status' => QrSession::STATUS_CLOSED,
                        'closed_at' => $capturedAt,
                        'updated_at' => $capturedAt,
                    ]);
            }

            $movements = $this->inventory->consume($order);

            // Per-sale commission split: apply the merchant's profile and
            // record the platform/bank/other/merchant breakdown. No active
            // profile ⇒ no rows (merchant keeps 100%). Snapshots the
            // percents so later profile edits never rewrite this sale.
            //
            // P-F7 — DEFERRED when any tender is pending_reconciliation:
            // the money is not confirmed until the platform admin matches
            // it against the bank file, so the split is NOT recorded here.
            // The admin's approval records it once the money is confirmed
            // (pos_admin ApprovePendingReconciliationAction — the twin of
            // this call). Inventory consumption and loyalty earn (below)
            // deliberately KEEP firing at pay time: the goods left the
            // shop regardless, and points are clawed back by voids.
            $saleCommissionIds = [];
            if (! $hasPendingTender) {
                $saleCommissionIds = $this->saleCommission->record(
                    $order,
                    $device,
                    $cardBaisas,
                    $giftBaisas,
                    $paymentIds[0] ?? null,
                    $event->client_event_id,
                );
            }

            // Loyalty earn (server-authoritative, §9.1.6): ordinary device
            // orders accrue under every rule the cashier named. QR-origin
            // orders instead ignore the event's ids and resolve every active
            // same-company rule server-side; qr_session_id remains authoritative
            // even after a counter finalize rewrites source to main_pos.
            // Phase D4 — a fully GIFTED order earns nothing (no spend ⇒ no
            // points), even if the device named earn rules.
            $loyaltyRuleIds = $giftBaisas >= $grandBaisas && $grandBaisas > 0
                ? []
                : $this->earnRuleIds($order, $payload);
            $this->assertLoyaltyEarnCanBeAttributed($order, $loyaltyRuleIds);

            $loyaltyTxnIds = [];
            foreach ($loyaltyRuleIds as $ruleId) {
                $txn = $this->loyalty->apply($order, $ruleId);
                if ($txn !== null) {
                    $loyaltyTxnIds[] = (int) $txn->id;
                }
            }

            // Loyalty redemption: the strict path records the requested spend.
            // API-001 contains only the expected insufficient-balance conflict:
            // the sale still settles, while the locked server balance is
            // debited as far as possible and the shortfall is review-flagged.
            $redeemTxn = null;
            $redeemAdjustment = null;
            $redeemWarning = null;
            // LAUNCH-P6 — a customer tablet order (Quick / To go too) redeems only
            // what staff approved on it (its slot), never an event's own block.
            if ($order->table_session_id !== null || $isTablet) {
                $loyaltyRedeem = TableLoyaltyDiscount::current($order);
            }
            if ($loyaltyRedeem !== null && isset($loyaltyRedeem['rule_id'])) {
                $ruleId = (int) $loyaltyRedeem['rule_id'];
                $pointsRequested = (int) ($loyaltyRedeem['points'] ?? 0);
                $stampsRequested = (int) ($loyaltyRedeem['stamps'] ?? 0);

                try {
                    $redeemTxn = $this->loyaltyRedeem->apply(
                        $order,
                        $ruleId,
                        $pointsRequested,
                        $stampsRequested,
                    );
                } catch (InsufficientLoyaltyBalanceException $exception) {
                    $reconciled = $this->loyaltyRedeem->reconcileInsufficientBalance(
                        $order,
                        $ruleId,
                        $pointsRequested,
                        $stampsRequested,
                        $exception->getMessage(),
                    );
                    $redeemTxn = $reconciled['redeem'];
                    $redeemAdjustment = $reconciled['adjustment'];
                    $redeemWarning = $reconciled['warning'];
                }
            }

            $roundupResult = null;
            if ($roundupBaisas > 0) {
                $roundupResult = $this->donationRecord->recordPaymentRoundup(
                    event: $event,
                    device: $device,
                    orderId: (int) $order->getKey(),
                    paymentId: (int) $paymentIds[0],
                    amountBaisas: $roundupBaisas,
                );
            }

            // LAUNCH-P5 (A6) — who took the payment (order.pay staff_id, new):
            // checked against the tenant and flagged when inactive or from
            // another branch; never a reason to refuse a paid sale.
            $payStaff = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
            try {
                TenantReferenceGuard::assertCashier($device, $payStaff, 'unknown payer');
            } catch (RuntimeException) {
                $device->syncIntegrityFlags[] = 'pay_staff_unknown:'.$payStaff;
            }
            // LAUNCH-P5 — a gift tender / loyalty redeem without a recorded
            // authorization is written to pos_approvals (never refused).
            $authorizations = $this->sales->forPay($event, $device, $orderUuid, $payments, is_array($payload['loyalty_redeem'] ?? null));
            // LAUNCH-P5 (A5) — cash landing in an already-closed shift's window.
            $lateShift = $this->shifts->recordLateCash($order, $paymentIds);

            $result = [
                'order_id' => (int) $order->id,
                'status' => 'paid',
                'payment_ids' => $paymentIds,
                'movements' => $movements,
                'sale_commission_ids' => $saleCommissionIds,
                // First id kept for back-compat; the full set under _ids.
                'loyalty_transaction_id' => $loyaltyTxnIds[0] ?? null,
                'loyalty_transaction_ids' => $loyaltyTxnIds,
                'loyalty_earned' => PublicTableLoyalty::earned($order),
                'loyalty_redeem_transaction_id' => $redeemTxn?->id,
                'loyalty_redeem_adjustment_id' => $redeemAdjustment?->id,
                'loyalty_redeem_warning' => $redeemWarning,
                'receipt_number' => $order->receipt_number,
                'temp_reference' => $order->temp_reference,
            ];

            if ($softposMismatch) {
                $result['softpos_mismatch'] = true;
            }
            if ($authorizations !== []) {
                $result['authorizations'] = $authorizations;
            }
            if ($lateShift !== null) {
                $result['late_for_shift'] = $lateShift['shift_uuid'];
            }
            if ($roundupResult !== null) {
                $result['roundup_donation_id'] = $roundupResult['roundup_donation_id'];
                $result['roundup_donation_uuid'] = $roundupResult['roundup_donation_uuid'];
                $result['roundup_payment_id'] = $roundupResult['payment_id'];
                $result['roundup_status'] = $roundupResult['status'];
            }

            return $result;
        });
    }

    /**
     * The local payment, donation and processed ACK commit together. Reuse the
     * donation handler's guarded snapshot forward only after that commit.
     *
     * @param  array<string, mixed>  $result
     */
    public function afterSyncEventCommit(SyncEvent $event, Device $device, array $result): void
    {
        $this->donationRecord->afterSyncEventCommit($event, $device, $result);
    }

    /**
     * Locate the first tender carrying enough SoftPOS evidence to preserve a
     * late authorisation. Terminal-order handling deliberately happens before
     * ordinary tender guards: after a tap, recording money is safer than
     * discarding evidence because another payload field is imperfect.
     *
     * @param  array<int|string, mixed>  $payments
     * @return array<string, mixed>|null
     */
    private function softPosEvidence(array $payments): ?array
    {
        foreach ($payments as $tender) {
            if (! is_array($tender) || ! array_key_exists('amount_baisas', $tender)) {
                continue;
            }

            $reference = $this->trimmedEvidence($tender['softpos_reference'] ?? null, 64);
            $authCode = $this->trimmedEvidence($tender['softpos_auth_code'] ?? null, 32);
            if ($reference === null && $authCode === null) {
                continue;
            }

            if (filter_var($tender['amount_baisas'], FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('invalid orphan tender amount in order.pay');
            }

            $tender['softpos_reference'] = $reference;
            $tender['softpos_auth_code'] = $authCode;

            return $tender;
        }

        return null;
    }

    private function trimmedEvidence(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException('SoftPOS evidence exceeds the supported length');
        }

        return $value;
    }

    /**
     * Persist an acquirer result that arrived after another settlement path
     * made the order terminal. Returning this result is what commits both the
     * payment and the sync event's processed stamp atomically.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function recordLateAuthorizationOrphan(
        SyncEvent $event,
        Order $order,
        Device $device,
        array $evidence,
        Carbon $capturedAt,
    ): array {
        $reference = $evidence['softpos_reference'];
        $authCode = $evidence['softpos_auth_code'];

        // Only an acquirer reference is strong enough for cross-event evidence
        // de-duplication. Auth codes are not unique; auth-only repeats with new
        // client_event_ids are preserved as separate money records. Replays of
        // the same event are already de-duplicated by the sync ledger.
        $existingEvidence = $reference !== null
            ? Payment::query()
                ->where('order_id', $order->getKey())
                ->where('device_id', $device->getKey())
                ->where('softpos_reference', $reference)
                ->orderBy('id')
                ->lockForUpdate()
                ->first()
            : null;

        if ($existingEvidence !== null) {
            return $this->withLateRoundup(
                result: [
                    'order_id' => (int) $order->getKey(),
                    'status' => (string) $order->status,
                    'orphan_tender' => data_get(
                        $existingEvidence->bank_response,
                        'qr_late_auth_orphan',
                    ) === true,
                    'orphan_payment_uuid' => (string) $existingEvidence->uuid,
                    'duplicate_softpos_evidence' => true,
                ],
                event: $event,
                device: $device,
                order: $order,
                payment: $existingEvidence,
            );
        }

        $originalSettlingDeviceId = null;
        if ($order->closed_at !== null) {
            $settlingDeviceIds = Payment::query()
                ->where('order_id', $order->getKey())
                ->where('captured_at', $order->closed_at)
                ->whereNotNull('device_id')
                ->distinct()
                ->pluck('device_id');
            if ($settlingDeviceIds->count() === 1) {
                $originalSettlingDeviceId = (int) $settlingDeviceIds->first();
            }
        }
        $clientBankResponse = is_array($evidence['bank_response'] ?? null)
            ? $evidence['bank_response']
            : [];
        $bankResponse = array_merge($clientBankResponse, [
            'qr_late_auth_orphan' => true,
            'order_uuid' => (string) $order->uuid,
            'original_settling_device_id' => $originalSettlingDeviceId !== null
                ? (int) $originalSettlingDeviceId
                : null,
        ]);

        $softpos = app(SnapshotCardSoftPos::class)->handle($device, $evidence);
        $payment = Payment::create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->getKey(),
            'method' => Payment::METHOD_CARD,
            'amount' => Money::toOmr((int) $evidence['amount_baisas']),
            'change_given' => null,
            'softpos_reference' => $reference,
            'softpos_auth_code' => $authCode,
            'status' => Payment::STATUS_PENDING_RECONCILIATION,
            'pending_reconciliation' => true,
            'device_id' => $device->getKey(),
            'terminal_id' => $device->terminal_id,
            'bank_id' => $device->bank_id,
            'bank_response' => $bankResponse,
            'captured_at' => $capturedAt,
        ] + $softpos);

        return $this->withLateRoundup(
            result: [
                'order_id' => (int) $order->getKey(),
                'status' => (string) $order->status,
                'orphan_tender' => true,
                'orphan_payment_uuid' => (string) $payment->uuid,
                'duplicate_softpos_evidence' => false,
            ],
            event: $event,
            device: $device,
            order: $order,
            payment: $payment,
        );
    }

    /**
     * Attach the frozen intent only to an accepted late SoftPOS charge. The
     * donation remains pending with its orphan payment; admin reconciliation
     * is the existing authority that later settles or rejects both.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withLateRoundup(
        array $result,
        SyncEvent $event,
        Device $device,
        Order $order,
        Payment $payment,
    ): array {
        if ($payment->softpos_mismatch) {
            $result['softpos_mismatch'] = true;
        }
        $roundupBaisas = (int) ($order->charge_roundup_amount_baisas ?? 0);
        $isPendingOrphan = data_get($payment->bank_response, 'qr_late_auth_orphan') === true
            && $payment->status === Payment::STATUS_PENDING_RECONCILIATION
            && (bool) $payment->pending_reconciliation;
        if ($roundupBaisas < 1 || ! $isPendingOrphan) {
            return $result;
        }

        $existingDonation = RoundupDonation::query()
            ->where('payment_id', $payment->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($existingDonation !== null) {
            $roundupResult = [
                'roundup_donation_id' => (int) $existingDonation->getKey(),
                'roundup_donation_uuid' => (string) $existingDonation->uuid,
                'payment_id' => (int) $payment->getKey(),
                'status' => (string) $existingDonation->status,
            ];
        } else {
            $roundupResult = $this->donationRecord->recordPaymentRoundup(
                event: $event,
                device: $device,
                orderId: (int) $order->getKey(),
                paymentId: (int) $payment->getKey(),
                amountBaisas: $roundupBaisas,
            );
        }

        $result['roundup_donation_id'] = $roundupResult['roundup_donation_id'];
        $result['roundup_donation_uuid'] = $roundupResult['roundup_donation_uuid'];
        $result['roundup_payment_id'] = $roundupResult['payment_id'];
        $result['roundup_status'] = $roundupResult['status'];

        return $result;
    }

    /**
     * Resolve loyalty EARN rule ids. QR-origin orders use every active rule
     * in their company; ordinary orders accept the v2 #3
     * `loyalty_rule_ids` (array — earn under several programs at once) or the
     * legacy single `loyalty_rule_id`. De-duped, positive ints only.
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function earnRuleIds(Order $order, array $payload): array
    {
        // LAUNCH-P6 — a customer tablet order earns like a QR one when a phone
        // linked a customer (every active rule), and nothing without one.
        $tablet = TabletOrder::query()->where('order_id', $order->id)->exists();
        if (($order->table_session_id !== null || $tablet) && $order->customer_id === null) {
            return [];
        }
        if ($order->qr_session_id !== null || $order->table_session_id !== null || $tablet) {
            return LoyaltyRule::query()
                ->where('company_id', (int) $order->company_id)
                ->where('status', 'active')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
        }

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
     * @param  list<int>  $loyaltyRuleIds
     */
    private function assertLoyaltyEarnCanBeAttributed(Order $order, array $loyaltyRuleIds): void
    {
        if ($loyaltyRuleIds === []
            || $order->customer_id !== null
            || $this->wasCreatedAsAnonymousWalkIn($order)) {
            return;
        }

        // EXIT-05 / IMP-2 - a production hard-delete nulls
        // pos_orders.customer_id. Without proven walk-in provenance, an
        // explicit earn request must park instead of silently losing value.
        throw new RuntimeException('cannot earn loyalty without a customer on the order');
    }

    /**
     * Prove that a NULL-customer order was born anonymous rather than losing
     * its customer later through the production FK's nullOnDelete action.
     *
     * order.create, order.hold and order.transfer persist their current source
     * event id on the order; the referenced event retains the nested payload.
     * Missing or ambiguous provenance is deliberately not treated as proof of
     * a walk-in: explicit earn context must then park for operator review
     * instead of silently discarding the promised earn.
     */
    private function wasCreatedAsAnonymousWalkIn(Order $order): bool
    {
        $clientEventId = is_string($order->client_event_id)
            ? trim($order->client_event_id)
            : '';
        if ($clientEventId === '') {
            return false;
        }

        $sourceDeviceIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter([
                $order->device_id,
                $order->transferred_from_device_id,
                $order->transferred_to_device_id,
            ], static fn (mixed $id): bool => $id !== null),
        )));
        if ($sourceDeviceIds === []) {
            return false;
        }

        $originEvents = SyncEvent::query()
            ->where('client_event_id', $clientEventId)
            ->whereIn('device_id', $sourceDeviceIds)
            ->whereIn('event_type', ['order.create', 'order.hold', 'order.transfer'])
            ->get()
            ->filter(
                static fn (SyncEvent $candidate): bool => (string) data_get(
                    $candidate->payload_json,
                    'order.uuid',
                ) === (string) $order->uuid,
            );

        if ($originEvents->count() !== 1) {
            return false;
        }

        $originEvent = $originEvents->first();

        return $originEvent instanceof SyncEvent
            && data_get($originEvent->payload_json, 'order.customer_id') === null;
    }

    /**
     * Fail-closed geofence for payment: at a fenced branch the device must
     * supply a GPS fix inside the fence, else the payment is rejected.
     *
     * @param  array<string, mixed>  $payload
     */
    private function enforceGeofence(Device $device, array $payload, SyncEvent $event): void
    {
        $branch = Branch::find($device->branch_id);
        // LAUNCH-P1 2a — the device's location mode decides whether a fence applies.
        $requirement = $this->geofence->requirement($device, $branch, $event->client_timestamp);
        if ($requirement === GeofenceGuard::BRANCH_LOCATION_MISSING) {
            throw new RuntimeException('payment rejected: '.GeofenceGuard::BRANCH_LOCATION_MISSING_MESSAGE);
        }
        if ($requirement !== GeofenceGuard::ENFORCE) {
            return;
        }

        $gps = $payload['gps'] ?? null;
        if (! is_array($gps) || ! isset($gps['lat'], $gps['lng'])) {
            throw new RuntimeException('payment rejected: a GPS fix is required at this geofenced branch');
        }

        $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
    }
}
