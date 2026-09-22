<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Pos\Loyalty\WriteLoyaltyTransactionAction;
use App\Actions\Qr\CloseDineInQrSessionAction;
use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\QrOrderRound;
use App\Models\RoundupDonation;
use App\Models\SaleCommission;
use App\Models\TableSession;
use App\Models\VoidReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared order void effects. Caller-specific sync validation stays in the handler. */
final class VoidOrderCoreAction
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
        private readonly WriteLoyaltyTransactionAction $loyalty,
        private readonly QrChargeRecoveryGuard $qrChargeRecovery,
        private readonly CloseDineInQrSessionAction $closeDineInSession,
        private readonly CloseTableSessionForOrderAction $closeTableSession,
    ) {}

    public function handle(Order $order, Device $device, Carbon $voidedAt, ?string $reason = null, ?VoidReason $voidReason = null): array
    {
        $orderUuid = (string) $order->uuid;
        $keepInventoryConsumed = $voidReason !== null && $voidReason->affects_inventory;

        return DB::transaction(function () use ($order, $orderUuid, $device, $voidedAt, $reason, $voidReason, $keepInventoryConsumed): array {
            // Re-read + lock the order INSIDE the txn before reversing stock. The
            // "already void" guard above is unlocked and is the SOLE idempotency
            // mechanism, so two concurrent order.void events with DIFFERENT
            // client_event_ids could both pass it and reverse() twice, over-
            // restoring branch stock. Locking + re-checking here serialises them.
            $order = Order::query()->whereKey($order->id)
                ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->lockForUpdate()->first();
            if ($order === null || $order->status === Order::STATUS_VOID) {
                throw new RuntimeException('order already void: '.$orderUuid);
            }

            if ($order->status === Order::STATUS_COMBINED) {
                throw new RuntimeException('combined order is read-only history: '.$orderUuid);
            }

            if (DB::table('pos_payment_reversals')->where('order_id', $order->id)
                ->where(function ($query): void {
                    $query->whereIn('status', ['pending', 'uncertain'])
                        ->orWhere(fn ($q) => $q->where('status', 'approved')->where('kind', 'refund'));
                })->exists()) {
                throw new RuntimeException('order has an active or approved refund reversal: '.$orderUuid);
            }

            $claimAt = now();
            if ($this->qrChargeRecovery->isAmbiguousCounterRecovery($order, $claimAt)
                && ! $this->qrChargeRecovery->isAttendedDevice($device)) {
                throw new RuntimeException(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                );
            }
            if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                if ($this->qrChargeRecovery->isAmbiguousCharge($order, $claimAt)) {
                    throw new RuntimeException(
                        'ambiguous QR charge requires fallback-to-counter before void: '.$orderUuid,
                    );
                }
                if (Order::query()
                    ->whereKey($order->getKey())
                    ->withLiveClaim($claimAt)
                    ->exists()) {
                    throw new RuntimeException('cannot void an order with a live charge claim: '.$orderUuid);
                }
                if (! Order::query()
                    ->whereKey($order->getKey())
                    ->withoutLiveClaim($claimAt)
                    ->exists()) {
                    throw new RuntimeException(
                        'cannot void an order with unresolved charge provenance: '.$orderUuid,
                    );
                }
            }
            // P-G7 — pending-verification delivery orders consumed inventory at
            // intake, so a void must unwind them like a paid sale. Their OTHER
            // effects never happened (no loyalty/round-up/commission for delivery
            // orders), and each reversal below is a no-op against empty rows.
            $wasPaid = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true);

            $order->update([
                'status' => Order::STATUS_VOID,
                'closed_at' => $voidedAt,
                'void_reason_id' => $voidReason?->id,
                'void_reason_label' => $voidReason?->name,
                'note' => $this->appendReason($order->note, $reason ?? $voidReason?->name),
            ]);
            TableLoyaltyDiscount::clear($order);
            $this->closeDineInSession->handle($order, $voidedAt);
            $this->closeTableSession->handle($order, $voidedAt, TableSession::CLOSE_VOIDED, (int) $device->getKey());
            QrOrderRound::query()
                ->where('order_id', $order->getKey())
                ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                ->update([
                    'status' => QrOrderRound::STATUS_REJECTED,
                    'resolved_at' => $voidedAt,
                    'resolved_by_device_id' => $device->getKey(),
                    'confirm_payload' => null,
                    'updated_at' => $voidedAt,
                ]);

            OrderItem::query()->where('order_id', $order->id)->update(['status' => OrderItem::STATUS_VOID]);

            // Only a PAID sale has settled side effects to unwind. An open
            // (never-paid) order moved no stock, loyalty, charity, or money.
            // Inventory: skipped when the reason says the food was made.
            $reversed = ($wasPaid && ! $keepInventoryConsumed) ? $this->inventory->reverse($order) : 0;
            $loyaltyReversed = $wasPaid ? $this->reverseLoyalty($order, $voidedAt) : 0;
            $roundupVoided = $wasPaid ? $this->reverseRoundup($order) : 0;
            $commissionRemoved = $wasPaid ? $this->reverseCommission($order) : 0;

            return [
                'order_id' => (int) $order->id,
                'status' => 'voided',
                'void_reason' => $voidReason?->code,
                'inventory_kept' => $wasPaid && $keepInventoryConsumed,
                'reversed' => $reversed,
                'loyalty_reversed' => $loyaltyReversed,
                'roundup_voided' => $roundupVoided,
                'commission_removed' => $commissionRemoved,
            ];
        });
    }

    /**
     * Append an inverse `adjust` for each earn/redeem the sale wrote, returning
     * the count of reversal rows appended. Reversing a REDEEM gives points/stamps
     * back (positive delta, never negative); reversing an EARN claws them back
     * (negative delta) but is clamped to the current balance so already-spent
     * points don't force the ledger negative — we only take back what remains.
     */
    public function reverseLoyalty(Order $order, Carbon $voidedAt, bool $earnOnly = false): int
    {
        $txns = LoyaltyTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', $earnOnly ? [LoyaltyTransaction::TYPE_EARN] : [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_REDEEM])
            ->orderBy('loyalty_account_id')
            ->orderBy('id')
            ->get();

        $groups = $txns
            ->groupBy(fn (LoyaltyTransaction $txn): int => (int) $txn->loyalty_account_id)
            ->sortKeys();

        $count = 0;
        foreach ($groups as $accountId => $accountTxns) {
            $account = LoyaltyAccount::query()->lockForUpdate()->find((int) $accountId);
            if ($account === null) {
                continue;
            }

            $currentPoints = (int) $account->point_balance;
            $currentStamps = (int) $account->stamp_count;
            if ($currentPoints < 0 || $currentStamps < 0) {
                throw new RuntimeException('Cannot reverse a negative loyalty account balance.');
            }

            // Restore this sale's redemptions before clawing back its earnings.
            // This preserves the pre-sale balance when both rows share an account.
            $ordered = $accountTxns
                ->where('type', LoyaltyTransaction::TYPE_REDEEM)
                ->sortBy('id')
                ->concat(
                    $accountTxns
                        ->where('type', LoyaltyTransaction::TYPE_EARN)
                        ->sortBy('id'),
                );

            foreach ($ordered as $txn) {
                $points = -(int) $txn->points_delta;
                $stamps = -(int) $txn->stamps_delta;

                // Clamp negative clawbacks to the locked balance still on hand.
                if ($points < 0) {
                    $points = -min(-$points, $currentPoints);
                }
                if ($stamps < 0) {
                    $stamps = -min(-$stamps, $currentStamps);
                }
                if ($points === 0 && $stamps === 0) {
                    continue;
                }

                $reversal = $this->loyalty->write(
                    $account,
                    LoyaltyTransaction::TYPE_ADJUST,
                    $points,
                    $stamps,
                    (int) $order->id,
                    'reversed from void',
                    $voidedAt,
                );
                $currentPoints = (int) $reversal->balance_after_points;
                $currentStamps = (int) $reversal->balance_after_stamps;
                $count++;
            }
        }

        return $count;
    }

    /**
     * Flip the order's charity round-up donation(s) to `void` and clear the
     * roundup breadcrumbs from the card payment they rode on. Returns the count
     * of donation rows voided. The forwarded charity_transaction (a settled
     * external record) is deliberately left intact.
     */
    private function reverseRoundup(Order $order): int
    {
        $donations = RoundupDonation::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', 'void')
            ->get();

        foreach ($donations as $donation) {
            $donation->update(['status' => 'void']);

            Payment::query()
                ->where('id', $donation->payment_id)
                ->update(['roundup_amount' => null, 'charity_transaction_id' => null]);
        }

        return $donations->count();
    }

    /**
     * Drop the per-party commission breakdown so the voided sale leaves no trace
     * in any settlement total. Returns the number of rows removed.
     *
     * v2 #17 guard: rows already CLAIMED by a payout (payout_id set) are NEVER
     * deleted — a created/paid payout's snapshot must stay backed by real rows
     * (the merchant's settlement is a frozen fact; voiding the sale afterwards
     * can't erase it). Only unsettled rows are reversed.
     *
     * Phase B mirror: the same holds for a commission INVOICE claim (invoice_id
     * set) — an issued invoice's total_owed is a frozen bill, so its backing rows
     * must survive a later void (else the merchant is billed for a sale whose
     * statement rows have vanished). Adjusting the bill is a separate admin void.
     *
     * ORDER-LEVEL guard: claims stamp only SOME of an order's rows (a payout
     * claims the merchant residual; an invoice the platform/other rows), but
     * both documents' statements are DERIVED from ALL of the order's rows.
     * Deleting the unclaimed siblings of a claimed order (the old per-row
     * guard) silently zeroed the platform/bank lines of the payout's branch
     * statement while its frozen header kept the full figures — a statement
     * that no longer added up. If ANY row of the order is claimed, ALL of its
     * rows survive the void.
     */
    private function reverseCommission(Order $order): int
    {
        $orderHasClaimedRow = SaleCommission::query()
            ->where('order_id', $order->id)
            ->where(function ($q): void {
                $q->whereNotNull('payout_id')->orWhereNotNull('invoice_id');
            })
            ->exists();
        if ($orderHasClaimedRow) {
            return 0;
        }

        // The DELETE re-checks the claim columns itself (not just the
        // exists() read above) so a payout/invoice claiming concurrently
        // under READ COMMITTED can never lose its just-claimed row.
        return SaleCommission::query()
            ->where('order_id', $order->id)
            ->whereNull('payout_id')
            ->whereNull('invoice_id')
            ->delete();
    }

    private function appendReason(?string $note, ?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return $note;
        }
        $tag = 'VOID: '.$reason;

        return $note === null || $note === '' ? $tag : $note.' | '.$tag;
    }
}
