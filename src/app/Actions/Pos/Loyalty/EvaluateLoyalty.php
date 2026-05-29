<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Device\Sync\ApplyLoyaltyEarnAction;
use App\Models\LoyaltyRule;

/**
 * Phase 8.4 — pure-function loyalty evaluator, ported verbatim (logic-wise)
 * from pos_merchant's App\Actions\Pos\Loyalty\EvaluateLoyalty so the server
 * and the future Flutter device produce IDENTICAL earn results (blueprint
 * §13 Phase 6 + §9.1.6 "loyalty point balance: server-authoritative").
 *
 * The only adaptation for pos_api: the rule type is matched on its string
 * value (pos_api has no LoyaltyRuleType enum) rather than the enum.
 *
 * No DB, no clock, no injection. The caller resolves the applicable rule
 * (status/validity) and passes the order shape + the customer's current
 * account balances for that rule; this computes what the order WOULD earn
 * and which redemptions the post-earn balance unlocks. It does NOT mutate —
 * {@see ApplyLoyaltyEarnAction} turns the result
 * into a loyalty_transaction. Money math is in INT BAISAS to avoid drift.
 *
 * Input:  $order = ['subtotal' => string]   // decimal:3 OMR, post-discount
 *         $account = ['stamp_count' => int, 'point_balance' => int]
 * Output: ['stampsEarned' => int, 'pointsEarned' => int, 'eligibleRedemptions' => list<array>]
 */
final class EvaluateLoyalty
{
    /**
     * @param  array{subtotal: string}  $order
     * @param  array{stamp_count?: int, point_balance?: int}  $account
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    public static function run(array $order, LoyaltyRule $rule, array $account = []): array
    {
        $config = is_array($rule->config_json) ? $rule->config_json : [];
        $subtotalBaisas = self::toBaisas((string) ($order['subtotal'] ?? '0'));
        $stampCount = (int) ($account['stamp_count'] ?? 0);
        $pointBalance = (int) ($account['point_balance'] ?? 0);

        return match ((string) $rule->type) {
            'visit_based' => self::evaluateVisit($config, $subtotalBaisas, $stampCount),
            'spend_based' => self::evaluateSpend($config, $subtotalBaisas, $pointBalance),
            default => ['stampsEarned' => 0, 'pointsEarned' => 0, 'eligibleRedemptions' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    private static function evaluateVisit(array $config, int $subtotalBaisas, int $stampCount): array
    {
        $minOrderBaisas = self::toBaisas((string) ($config['min_order_value'] ?? '0'));
        $stampsRequired = (int) ($config['stamps_required'] ?? 0);

        // One stamp per qualifying order (subtotal at/above the minimum).
        $stampsEarned = $subtotalBaisas >= $minOrderBaisas ? 1 : 0;
        $newStamps = $stampCount + $stampsEarned;

        $eligible = [];
        if ($stampsRequired > 0 && $newStamps >= $stampsRequired) {
            $eligible[] = [
                'type' => 'stamp_reward',
                'stamps_per_reward' => $stampsRequired,
                'rewards_available' => intdiv($newStamps, $stampsRequired),
                'reward_type' => $config['reward_type'] ?? null,
                'reward_value' => $config['reward_value'] ?? null,
                'reward_product_id' => isset($config['reward_product_id']) ? (int) $config['reward_product_id'] : null,
            ];
        }

        return [
            'stampsEarned' => $stampsEarned,
            'pointsEarned' => 0,
            'eligibleRedemptions' => $eligible,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    private static function evaluateSpend(array $config, int $subtotalBaisas, int $pointBalance): array
    {
        $pointsPerOmr = (int) ($config['points_per_omr'] ?? 0);
        $redemptionPoints = (int) ($config['redemption_points'] ?? 0);
        $minRedemption = (int) ($config['min_redemption_points'] ?? 0);
        $redemptionValue = (string) ($config['redemption_value'] ?? '0.000');

        // points = floor(omr_spent × points_per_omr), in integer baisas space.
        $pointsEarned = intdiv($subtotalBaisas * $pointsPerOmr, 1000);
        $newBalance = $pointBalance + $pointsEarned;

        $eligible = [];
        $threshold = max($minRedemption, $redemptionPoints);
        if ($redemptionPoints > 0 && $newBalance >= $threshold) {
            $eligible[] = [
                'type' => 'points',
                'points_per_redemption' => $redemptionPoints,
                'reward_value' => $redemptionValue,
                'max_redeemable_units' => intdiv($newBalance, $redemptionPoints),
            ];
        }

        return [
            'stampsEarned' => 0,
            'pointsEarned' => $pointsEarned,
            'eligibleRedemptions' => $eligible,
        ];
    }

    private static function toBaisas(string $omr): int
    {
        return (int) round(((float) $omr) * 1000);
    }
}
