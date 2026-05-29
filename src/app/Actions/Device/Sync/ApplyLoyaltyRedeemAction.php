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
 * Unlike earn (best-effort), redemption is STRICT: a redeem with no customer,
 * an unknown rule, no account, or an over-balance spend throws — failing the
 * pay event — because the bill was already reduced and a silent miss would
 * desync the books. (Reconciling an optimistic over-redeem with a customer
 * note, per §9.1.6, is a later refinement.)
 */
class ApplyLoyaltyRedeemAction
{
    public function __construct(
        private readonly WriteLoyaltyTransactionAction $writer,
    ) {}

    public function apply(Order $order, int $loyaltyRuleId, int $pointsRedeemed, int $stampsRedeemed): ?LoyaltyTransaction
    {
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
}
