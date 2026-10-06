<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Support\CanonicalPhone;
use App\Support\CustomerIdentity;
use App\Support\Money;

/**
 * LAUNCH-P6 (tester call 8) — the customer tablet's view of loyalty: points
 * and redeemable blocks only, never a name. The rewards a tablet may ask for
 * are the ones staff can approve on a table bill
 * ({@see TableLoyaltyDiscount::redeem()}): an active, in-date, fixed-value
 * reward of this merchant (points: redemption_points → redemption_value;
 * stamps: stamps_required → a fixed reward_value).
 */
final class TabletLoyalty
{
    /** The most blocks one request may name (the table adjustment's limit). */
    public const MAX_BLOCKS = 50;

    /**
     * An Omani number in E.164 (+968 and 8 digits), or null. Arabic-Indic
     * digits, spaces, a leading 00 or +968 are accepted.
     */
    public static function phone(?string $raw): ?string
    {
        $canonical = CanonicalPhone::of($raw);
        if ($canonical === null || ! preg_match('/^968[0-9]{8}$/', $canonical)) {
            return null;
        }

        return '+'.$canonical;
    }

    /**
     * The live customer of this merchant for an E.164 phone (merges followed),
     * or null. Fix order 7 (F-23) — stored in any equivalent Omani form, the
     * one with points first ({@see CustomerIdentity::equivalentMatch()}).
     */
    public static function customer(int $companyId, string $phone): ?Customer
    {
        return CustomerIdentity::equivalentMatch($companyId, $phone);
    }

    /**
     * @return array{rule: LoyaltyRule, kind: string, unit: int, value_baisas: int}|null
     */
    public static function reward(int $companyId, int $ruleId): ?array
    {
        $rule = LoyaltyRule::query()->whereKey($ruleId)->where('company_id', $companyId)->where('status', 'active')->first();
        if ($rule === null) {
            return null;
        }
        $config = $rule->config_json ?? [];
        $points = $rule->type === 'spend_based';
        $unit = (int) ($config[$points ? 'redemption_points' : 'stamps_required'] ?? 0);
        $value = Money::toBaisas($config[$points ? 'redemption_value' : 'reward_value'] ?? 0);
        if (! in_array($rule->type, ['spend_based', 'visit_based'], true)
            || (! $points && ! in_array($config['reward_type'] ?? null, ['fixed', 'fixed_off'], true)) || $unit < 1 || $value < 1
            || ($rule->validity_start !== null && $rule->validity_start->isFuture())
            || ($rule->validity_end !== null && $rule->validity_end->isPast())) {
            return null;
        }

        return ['rule' => $rule, 'kind' => $points ? 'points' : 'stamps', 'unit' => $unit, 'value_baisas' => $value];
    }

    /**
     * Balances and redeemable blocks of one customer, this merchant only.
     * The balance already reserved by other unpaid bills is not redeemable.
     *
     * @return list<array<string, mixed>>
     */
    public static function accounts(int $companyId, int $customerId): array
    {
        $accounts = LoyaltyAccount::query()->where('company_id', $companyId)->where('customer_id', $customerId)
            ->orderBy('loyalty_rule_id')->get();
        $rows = [];
        foreach ($accounts as $account) {
            $reward = self::reward($companyId, (int) $account->loyalty_rule_id);
            $kind = $reward['kind'] ?? (LoyaltyRule::query()->whereKey($account->loyalty_rule_id)->value('type') === 'visit_based' ? 'stamps' : 'points');
            $balance = (int) ($kind === 'stamps' ? $account->stamp_count : $account->point_balance);
            $blocks = 0;
            if ($reward !== null) {
                $available = $balance - TableLoyaltyDiscount::pendingUnits($companyId, $customerId, (int) $account->loyalty_rule_id, $kind);
                $blocks = max(0, min(self::MAX_BLOCKS, intdiv(max(0, $available), $reward['unit'])));
            }
            $rows[] = [
                'rule_id' => (int) $account->loyalty_rule_id,
                'rule_name' => $reward['rule']->name ?? null,
                'kind' => $kind,
                'balance' => $balance,
                'redeemable' => $reward !== null,
                'block_units' => $reward['unit'] ?? null,
                'block_value_baisas' => $reward['value_baisas'] ?? null,
                'redeemable_blocks' => $blocks,
            ];
        }

        return $rows;
    }

    /** The local 8 digits masked for staff: 9xxx1234. */
    public static function masked(?string $phone): ?string
    {
        $canonical = CanonicalPhone::of($phone);
        if ($canonical === null) {
            return null;
        }
        $local = strlen($canonical) > 8 ? substr($canonical, -8) : $canonical;

        return substr($local, 0, 1).'xxx'.substr($local, -4);
    }
}
