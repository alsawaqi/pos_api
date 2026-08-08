<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Pos\Loyalty\WriteLoyaltyTransactionAction;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use RuntimeException;

/**
 * Phase 8.5 — records a loyalty REDEMPTION at sale (the points/stamps SPENT).
 *
 * The redemption's monetary value is already on the order as a snapshot
 * discount (§6.8); this writes the symmetric ledger decrement so the
 * server-authoritative balance stays correct. Called from
 * {@see Handlers\PayOrderHandler} when the pay event carries a
 * `loyalty_redeem` block.
 *
 * Missing customer, rule, or account data remains strict because no valid
 * ledger can own the debit. API-001 narrows one expected failure: when a valid
 * account's server balance no longer covers the device's optimistic spend,
 * the pay handler catches that specific conflict and reconciles it against the
 * locked available balance.
 */
class ApplyLoyaltyRedeemAction
{
    public function __construct(
        private readonly WriteLoyaltyTransactionAction $writer,
    ) {}

    public function apply(Order $order, int $loyaltyRuleId, int $pointsRedeemed, int $stampsRedeemed): ?LoyaltyTransaction
    {
        if ($pointsRedeemed < 0 || $stampsRedeemed < 0) {
            throw new RuntimeException('invalid loyalty redemption: points and stamps must be non-negative');
        }

        if ($pointsRedeemed <= 0 && $stampsRedeemed <= 0) {
            return null;
        }

        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;
        if ($customerId === null) {
            throw new RuntimeException('cannot redeem loyalty without a customer on the order');
        }

        $rule = LoyaltyRule::query()
            ->where('company_id', $order->company_id)
            ->find($loyaltyRuleId);
        if ($rule === null) {
            throw new RuntimeException('unknown loyalty rule for redemption: '.$loyaltyRuleId);
        }

        $account = LoyaltyAccount::query()
            ->where('customer_id', $customerId)
            ->where('loyalty_rule_id', $rule->id)
            ->first();
        if ($account === null) {
            throw new RuntimeException('no loyalty account to redeem from');
        }

        // Negative deltas; the writer guards the balance against going negative.
        return $this->writer->write(
            $account,
            LoyaltyTransaction::TYPE_REDEEM,
            -$pointsRedeemed,
            -$stampsRedeemed,
            (int) $order->id,
            'redeemed at sale',
            $order->closed_at,
        );
    }

    /**
     * Resolve only a confirmed insufficient-balance conflict. The same strict
     * customer/rule/account checks are repeated so a concurrent deletion or
     * tenant mismatch cannot be converted into a successful sale.
     *
     * @return array{
     *     redeem: LoyaltyTransaction|null,
     *     adjustment: LoyaltyTransaction|null,
     *     points_applied: int,
     *     stamps_applied: int,
     *     points_shortfall: int,
     *     stamps_shortfall: int,
     *     warning: string|null
     * }
     */
    public function reconcileInsufficientBalance(
        Order $order,
        int $loyaltyRuleId,
        int $pointsRequested,
        int $stampsRequested,
        string $failureReason,
    ): array {
        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;
        if ($customerId === null) {
            throw new RuntimeException('cannot redeem loyalty without a customer on the order');
        }

        $rule = LoyaltyRule::query()
            ->where('company_id', $order->company_id)
            ->findOrFail($loyaltyRuleId);
        $account = LoyaltyAccount::query()
            ->where('customer_id', $customerId)
            ->where('loyalty_rule_id', $rule->id)
            ->firstOrFail();

        $result = $this->writer->writeClampedRedemption(
            $account,
            $pointsRequested,
            $stampsRequested,
            (int) $order->id,
            $failureReason,
            $order->closed_at,
        );

        return [
            ...$result,
            'warning' => $result['adjustment']?->reason,
        ];
    }
}
