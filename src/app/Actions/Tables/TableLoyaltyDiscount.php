<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Shift;
use App\Models\TableSessionEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** One active redemption slot, with immutable positive rows and mirrored reversals. */
final class TableLoyaltyDiscount
{
    public const TYPES = ['table_loyalty_redeem', 'table_loyalty_reversal'];

    public static function rows(Order $order)
    {
        return OrderDiscount::query()->where('order_id', $order->id)->whereIn('amount_type_snapshot', self::TYPES);
    }

    public static function amount(Order $order): int
    {
        return self::rows($order)->get()->sum(fn ($row): int => Money::toBaisas($row->amount));
    }

    public static function current(Order $order): ?array
    {
        if (self::amount($order) <= 0) {
            return null;
        }
        $row = self::rows($order)->where('amount', '>', 0)->orderByDesc('id')->firstOrFail();
        if (! preg_match('/^(points|stamps) × ([0-9]+) — rule ([0-9]+)$/u', (string) $row->reason, $m)) {
            throw new \RuntimeException('Invalid saved table redemption.');
        }

        return ['rule_id' => (int) $m[3], 'points' => $m[1] === 'points' ? (int) $m[2] : 0,
            'stamps' => $m[1] === 'stamps' ? (int) $m[2] : 0, 'amount_baisas' => Money::toBaisas($row->amount),
            'discount_row_id' => (int) $row->id, 'name' => $row->name_snapshot];
    }

    public static function clear(Order $order): void
    {
        $amount = self::amount($order);
        if ($amount <= 0) {
            return;
        }
        $row = self::rows($order)->where('amount', '>', 0)->orderByDesc('id')->firstOrFail()->replicate();
        $row->amount = Money::toOmr(-$amount);
        $row->amount_type_snapshot = 'table_loyalty_reversal';
        $row->applied_at = now();
        $row->save();
    }

    /** Runs inside the existing locked table-graph adjustment transaction. */
    public static function redeem(Order $order, array $intent, int $availableNet): array
    {
        $staffId = $intent['approved_by_staff_id'] ?? null;
        if (trim((string) ($intent['authorized_by'] ?? '')) === '' || $staffId === null
            || ! DB::table('pos_staff')->where('id', $staffId)->where('company_id', $order->company_id)->lockForUpdate()->first()) {
            throw AdjustTableBillAction::refusal('approval_required', 'Staff approval is required for this redemption.');
        }
        // Shared customer/staff locks serialize limits across different branch graphs.
        $customer = $order->customer_id === null ? null : Customer::query()->whereKey($order->customer_id)
            ->where('company_id', $order->company_id)->lockForUpdate()->first();
        if ($customer === null) {
            throw AdjustTableBillAction::refusal('loyalty_no_customer', 'Attach a customer before redeeming points.');
        }
        $rule = LoyaltyRule::query()->whereKey($intent['rule_id'])->where('company_id', $order->company_id)
            ->where('status', 'active')->first();
        $config = $rule?->config_json ?? [];
        $points = $rule?->type === 'spend_based';
        $unit = (int) ($config[$points ? 'redemption_points' : 'stamps_required'] ?? 0);
        $value = Money::toBaisas($config[$points ? 'redemption_value' : 'reward_value'] ?? 0);
        if ($rule === null || ! in_array($rule->type, ['spend_based', 'visit_based'], true)
            || (! $points && ! in_array($config['reward_type'] ?? null, ['fixed', 'fixed_off'], true)) || $unit < 1 || $value < 1
            || ($rule->validity_start !== null && $rule->validity_start->isFuture())
            || ($rule->validity_end !== null && $rule->validity_end->isPast())) {
            throw AdjustTableBillAction::refusal('loyalty_rule_unsupported', 'Choose an active fixed-value loyalty reward from this merchant.');
        }
        $blocks = (int) $intent['blocks'];
        $units = $unit * $blocks;
        $amount = $value * $blocks;
        $account = LoyaltyAccount::query()->where('company_id', $order->company_id)->where('customer_id', $customer->id)
            ->where('loyalty_rule_id', $rule->id)->lockForUpdate()->first();
        $reserved = self::pendingUnits($order, (int) $rule->id, $points ? 'points' : 'stamps');
        if ($units > (int) ($account?->{$points ? 'point_balance' : 'stamp_count'} ?? 0) - $reserved) {
            throw AdjustTableBillAction::refusal('loyalty_insufficient', 'There are not enough available points or stamps for these blocks.');
        }
        if ($amount >= $availableNet) {
            throw AdjustTableBillAction::refusal('adjustment_exceeds_bill', 'The redemption must leave a positive amount to pay.');
        }
        $day = now('Asia/Muscat')->startOfDay()->utc();
        $end = $day->copy()->addDay();
        $customerRows = TableSessionEvent::query()->where('company_id', $order->company_id)->where('event_type', 'adjusted')
            ->where('payload->kind', 'loyalty')->where('payload->mode', 'redeem')->where('payload->customer_id', (int) $customer->id)
            ->where('created_at', '>=', $day)->where('created_at', '<', $end)->get()->pluck('payload.discount_row_id');
        $tableCount = DB::table('pos_order_discounts as d')->join('pos_orders as o', 'o.id', '=', 'd.order_id')
            ->where('o.company_id', $order->company_id)->whereIn('d.id', $customerRows)->where('o.status', '!=', Order::STATUS_VOID)
            ->where('d.amount_type_snapshot', 'table_loyalty_redeem')->where('d.amount', '>', 0)
            ->where('d.applied_at', '>=', $day)->where('d.applied_at', '<', $end)->count();
        $counterCount = DB::table('pos_loyalty_transactions as t')->join('pos_loyalty_accounts as a', 'a.id', '=', 't.loyalty_account_id')
            ->join('pos_orders as o', 'o.id', '=', 't.order_id')->where('t.company_id', $order->company_id)
            ->where('a.customer_id', $customer->id)->where('a.company_id', $order->company_id)
            ->whereNull('o.table_session_id')->where('o.status', '!=', Order::STATUS_VOID)->where('t.type', 'redeem')
            ->where('t.occurred_at', '>=', $day)->where('t.occurred_at', '<', $end)->count();
        if ($tableCount + $counterCount >= 3) {
            throw AdjustTableBillAction::refusal('loyalty_customer_limit', 'This customer has reached the daily redemption limit.');
        }
        $shift = Shift::query()->where('company_id', $order->company_id)->where('staff_id', $staffId)
            ->where('status', Shift::STATUS_OPEN)->whereNull('closed_at')->orderByDesc('opened_at')->lockForUpdate()->first();
        $staffCount = TableSessionEvent::query()->where('company_id', $order->company_id)->where('event_type', 'adjusted')
            ->where('payload->kind', 'loyalty')->where('payload->mode', 'redeem')->where('payload->approved_by_staff_id', (int) $staffId)
            ->where('created_at', '>=', $shift?->opened_at ?? $day)->when($shift === null, fn ($q) => $q->where('created_at', '<', $end))->count();
        if ($staffCount >= 10) {
            throw AdjustTableBillAction::refusal('loyalty_staff_limit', 'This staff member has reached the redemption approval limit for this shift.');
        }
        self::clear($order);
        $row = OrderDiscount::query()->create(['company_id' => $order->company_id, 'branch_id' => $order->branch_id,
            'order_id' => $order->id, 'order_item_id' => null, 'discount_id' => null, 'offer_id' => null,
            'name_snapshot' => $rule->name, 'amount_type_snapshot' => 'table_loyalty_redeem', 'amount' => Money::toOmr($amount),
            'reason' => ($points ? 'points' : 'stamps').' × '.$units.' — rule '.$rule->id, 'applied_at' => now()]);

        return ['rule_id' => (int) $rule->id, 'blocks' => $blocks, 'points' => $points ? $units : 0,
            'stamps' => $points ? 0 : $units, 'amount_baisas' => $amount, 'discount_row_id' => (int) $row->id,
            'customer_id' => (int) $customer->id, 'shift_id' => $shift === null ? null : (int) $shift->id];
    }

    /** Read under redeem's customer/account locks, across all branches of this merchant. */
    private static function pendingUnits(Order $order, int $ruleId, string $unit): int
    {
        // Include this bill too: replacement retains the existing availability rule.
        // Do not lock other bills here; their table-graph locks precede the customer
        // lock. Reading committed slots avoids reversing that lock order.
        $bills = Order::query()->where('company_id', $order->company_id)
            ->where('customer_id', $order->customer_id)->whereNotNull('table_session_id')
            ->whereNotIn('status', [Order::STATUS_PAID, Order::STATUS_VOID])
            ->whereIn('id', OrderDiscount::query()->select('order_id')
                ->whereIn('amount_type_snapshot', self::TYPES)
                ->groupBy('order_id')->havingRaw('SUM(amount) > 0'))->get();
        $reserved = 0;
        foreach ($bills as $bill) {
            $pending = self::current($bill);
            if (($pending['rule_id'] ?? null) === $ruleId) {
                $reserved += $pending[$unit];
            }
        }

        return $reserved;
    }
}
