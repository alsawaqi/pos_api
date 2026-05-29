<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Pos\Loyalty\EvaluateLoyalty;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 8.4 — server-authoritative loyalty EARN at sale (blueprint §9.1.6).
 *
 * Called from {@see Handlers\PayOrderHandler} when a paid order carries a
 * customer and the pay event named a loyalty_rule_id (the rule the cashier
 * chose at POS, §5.8). Runs the shared {@see EvaluateLoyalty} on the order's
 * post-discount subtotal and writes a single `earn` row into the loyalty
 * ledger, bumping the account's running balance.
 *
 * Mirrors pos_merchant's WriteLoyaltyTransactionAction: lock the account
 * row, compute the new balances, append the txn with balance_after_*
 * snapshots, update the account — all in one transaction so account ≡
 * Σ(transactions) can never drift. POS-driven, so recorded_by_user_id is
 * null. Redemption (points SPENT) is recorded as the order's discount and
 * its ledger row lands in a later slice — this only EARNS.
 */
class ApplyLoyaltyEarnAction
{
    /**
     * Returns the earn transaction, or null when nothing was earned
     * (no customer, unknown/cross-tenant/paused rule, or zero accrual).
     */
    public function apply(Order $order, int $loyaltyRuleId): ?LoyaltyTransaction
    {
        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;
        if ($customerId === null) {
            return null;
        }

        $rule = LoyaltyRule::query()
            ->where('company_id', $order->company_id)
            ->find($loyaltyRuleId);

        if ($rule === null || (string) $rule->status !== 'active') {
            return null;
        }

        return DB::transaction(function () use ($order, $rule, $customerId): ?LoyaltyTransaction {
            $account = LoyaltyAccount::query()->firstOrCreate(
                ['customer_id' => $customerId, 'loyalty_rule_id' => $rule->id],
                ['uuid' => (string) Str::uuid(), 'company_id' => $order->company_id],
            );

            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);

            // Loyalty accrues on the post-discount goods subtotal.
            $eligibleBaisas = max(0, Money::toBaisas($order->subtotal) - Money::toBaisas($order->discount_total));
            $result = EvaluateLoyalty::run(
                ['subtotal' => Money::toOmr($eligibleBaisas)],
                $rule,
                ['stamp_count' => (int) $locked->stamp_count, 'point_balance' => (int) $locked->point_balance],
            );

            $stampsEarned = (int) $result['stampsEarned'];
            $pointsEarned = (int) $result['pointsEarned'];
            if ($stampsEarned === 0 && $pointsEarned === 0) {
                return null;
            }

            $newStamps = (int) $locked->stamp_count + $stampsEarned;
            $newPoints = (int) $locked->point_balance + $pointsEarned;

            $txn = LoyaltyTransaction::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'loyalty_account_id' => $locked->id,
                'type' => LoyaltyTransaction::TYPE_EARN,
                'points_delta' => $pointsEarned,
                'stamps_delta' => $stampsEarned,
                'balance_after_points' => $newPoints,
                'balance_after_stamps' => $newStamps,
                'reason' => null,
                'order_id' => $order->id,
                'recorded_by_user_id' => null,
                'occurred_at' => $order->closed_at ?? now(),
                'created_at' => now(),
            ]);

            $locked->update([
                'point_balance' => $newPoints,
                'stamp_count' => $newStamps,
                'last_activity_at' => now(),
            ]);

            return $txn;
        });
    }
}
