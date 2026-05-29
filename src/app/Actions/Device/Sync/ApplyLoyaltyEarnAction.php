<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Pos\Loyalty\EvaluateLoyalty;
use App\Actions\Pos\Loyalty\WriteLoyaltyTransactionAction;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Phase 8.4 — server-authoritative loyalty EARN at sale (blueprint §9.1.6).
 *
 * Called from {@see Handlers\PayOrderHandler} when a paid order carries a
 * customer and the pay event named a loyalty_rule_id (the rule the cashier
 * chose at POS, §5.8). Runs the shared {@see EvaluateLoyalty} on the order's
 * post-discount subtotal and delegates the ledger write to
 * {@see WriteLoyaltyTransactionAction}.
 *
 * Best-effort: returns null (no error) when there's no customer, the rule is
 * unknown / cross-tenant / paused, or nothing accrued. Redemption — the
 * symmetric SPEND — lives in {@see ApplyLoyaltyRedeemAction}.
 */
class ApplyLoyaltyEarnAction
{
    public function __construct(
        private readonly WriteLoyaltyTransactionAction $writer,
    ) {}

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

        $account = LoyaltyAccount::query()->firstOrCreate(
            ['customer_id' => $customerId, 'loyalty_rule_id' => $rule->id],
            ['uuid' => (string) Str::uuid(), 'company_id' => $order->company_id],
        );

        // Loyalty accrues on the post-discount goods subtotal.
        $eligibleBaisas = max(0, Money::toBaisas($order->subtotal) - Money::toBaisas($order->discount_total));
        $result = EvaluateLoyalty::run(
            ['subtotal' => Money::toOmr($eligibleBaisas)],
            $rule,
            ['stamp_count' => (int) $account->stamp_count, 'point_balance' => (int) $account->point_balance],
        );

        $stampsEarned = (int) $result['stampsEarned'];
        $pointsEarned = (int) $result['pointsEarned'];
        if ($stampsEarned === 0 && $pointsEarned === 0) {
            return null;
        }

        return $this->writer->write(
            $account,
            LoyaltyTransaction::TYPE_EARN,
            $pointsEarned,
            $stampsEarned,
            (int) $order->id,
            null,
            $order->closed_at,
        );
    }
}
