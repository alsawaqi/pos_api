<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Pos\Loyalty\WriteLoyaltyTransactionAction;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SaleCommission;
use App\Models\SyncEvent;
use App\Models\VoidReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8.3 / v2 #14 — processes an `order.void` sync event: cancels the order
 * and its lines, and — if the order had already been PAID — fully unwinds every
 * accounting side effect that order.pay produced so a voided sale nets to zero:
 *
 *   1. inventory       — reverse stock consumption (recipe + unit), restoring
 *                        each branch balance ({@see ConsumeInventoryAction}).
 *   2. loyalty         — for every earn/redeem the sale wrote, append an inverse
 *                        `adjust` ledger row so the customer's balance returns to
 *                        where it was (a clawed-back earn is clamped to the
 *                        available balance — if the points were already spent we
 *                        take back only what's left, never forcing the ledger
 *                        negative and never failing the void).
 *   3. round-up        — flip the charity round-up donation row to `void` and
 *                        clear the card payment's roundup breadcrumbs. The
 *                        donation already forwarded to the charity app is NOT
 *                        reversed here (a settled external charity_transaction is
 *                        out of scope — refunding it is a manual charity-side op).
 *   4. commission      — delete the per-party commission breakdown so the voided
 *                        sale drops out of every settlement/payout total.
 *
 * The whole unwind runs inside ONE transaction with the status→VOID flip, and
 * the "already void" guard is the SOLE idempotency mechanism — a replayed
 * order.void throws (never re-reverses). A payment REFUND record (negative
 * payment / a `refunded` status) is intentionally NOT written here: the void +
 * these reversals ARE the books, and a real card refund needs a Soft POS
 * terminal reversal, which is a separate flow. Void is scoped to the device's
 * company + branch.
 */
class VoidOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
        private readonly WriteLoyaltyTransactionAction $loyalty,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;

        if (! is_string($orderUuid)) {
            throw new RuntimeException('invalid order.void payload: order_uuid required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for void: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID) {
            throw new RuntimeException('order already void: '.$orderUuid);
        }

        $voidedAt = isset($payload['voided_at']) ? Carbon::parse((string) $payload['voided_at']) : now();
        // P-G7 — pending-verification delivery orders consumed inventory at
        // intake, so a void must unwind them like a paid sale. Their OTHER
        // effects never happened (no loyalty/round-up/commission for delivery
        // orders), and each reversal below is a no-op against empty rows.
        $wasPaid = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true);
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        // Phase B (Additions §1.2) — resolve the picked void reason code,
        // tenant-scoped. affects_inventory = TRUE means the food was actually
        // made: the recipe ingredients STAY consumed (no inventory reverse)
        // and the loss surfaces in the Loss/Waste voids breakdown. No / an
        // unknown reason keeps the legacy behaviour (full reverse).
        $voidReason = null;
        if (isset($payload['void_reason_id'])) {
            $voidReason = VoidReason::query()
                ->where('company_id', $device->company_id)
                ->find((int) $payload['void_reason_id']);
            if ($voidReason === null) {
                throw new RuntimeException('void reason not found for this company: '.$payload['void_reason_id']);
            }
        }
        $keepInventoryConsumed = $voidReason !== null && $voidReason->affects_inventory;

        return DB::transaction(function () use ($order, $voidedAt, $wasPaid, $reason, $voidReason, $keepInventoryConsumed): array {
            $order->update([
                'status' => Order::STATUS_VOID,
                'closed_at' => $voidedAt,
                'void_reason_id' => $voidReason?->id,
                'void_reason_label' => $voidReason?->name,
                'note' => $this->appendReason($order->note, $reason ?? $voidReason?->name),
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
    private function reverseLoyalty(Order $order, Carbon $voidedAt): int
    {
        $txns = LoyaltyTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_REDEEM])
            ->get();

        $count = 0;
        foreach ($txns as $txn) {
            $account = LoyaltyAccount::query()->find($txn->loyalty_account_id);
            if ($account === null) {
                continue;
            }

            $points = -(int) $txn->points_delta;
            $stamps = -(int) $txn->stamps_delta;

            // Clamp negative clawbacks to the balance still on hand.
            if ($points < 0) {
                $points = -min(-$points, (int) $account->point_balance);
            }
            if ($stamps < 0) {
                $stamps = -min(-$stamps, (int) $account->stamp_count);
            }
            if ($points === 0 && $stamps === 0) {
                continue;
            }

            $this->loyalty->write(
                $account,
                LoyaltyTransaction::TYPE_ADJUST,
                $points,
                $stamps,
                (int) $order->id,
                'reversed from void',
                $voidedAt,
            );
            $count++;
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
     */
    private function reverseCommission(Order $order): int
    {
        return SaleCommission::query()
            ->where('order_id', $order->id)
            ->whereNull('payout_id')
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
