<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;

/** Public projection: scope comes only from the authenticated QR credential. */
final class PublicTableLoyalty
{
    public static function order(QrSession $session): ?Order
    {
        if (! $session->isDineIn()) {
            return null;
        }
        $order = $session->orders()->where('company_id', $session->company_id)
            ->where('branch_id', $session->branch_id)->latest('id')->first();
        if ($order !== null) {
            return $order;
        }
        $seat = TableSession::query()->whereKey($session->table_session_id)
            ->where('company_id', $session->company_id)->where('branch_id', $session->branch_id)
            ->where('table_id', $session->table_id)->first();
        if ($seat?->merged_into_id !== null) {
            $seat = TableSession::query()->whereKey($seat->merged_into_id)
                ->where('company_id', $session->company_id)->where('branch_id', $session->branch_id)->first();
        }

        return $seat?->order_id === null ? null : Order::query()->whereKey($seat->order_id)
            ->where('table_session_id', $seat->id)->where('company_id', $session->company_id)
            ->where('branch_id', $session->branch_id)->first();
    }

    public static function hasPhone(?Order $order): bool
    {
        return $order?->customer_id !== null && Customer::query()->whereKey($order->customer_id)
            ->where('company_id', $order->company_id)->whereNotNull('phone')->where('phone', '!=', '')->exists();
    }

    public static function accounts(?Order $order): array
    {
        if (! self::hasPhone($order)) {
            return [];
        }

        return LoyaltyAccount::query()->join('pos_loyalty_rules as r', 'r.id', '=', 'pos_loyalty_accounts.loyalty_rule_id')
            ->where('pos_loyalty_accounts.company_id', $order->company_id)->where('customer_id', $order->customer_id)
            ->where('r.company_id', $order->company_id)->whereNull('r.deleted_at')->orderBy('loyalty_rule_id')
            ->get(['loyalty_rule_id', 'r.name', 'r.type', 'point_balance', 'stamp_count'])->map(fn ($a): array => [
                'rule_id' => (int) $a->loyalty_rule_id, 'rule_name' => $a->name,
                'kind' => $a->type === 'visit_based' ? 'stamps' : 'points',
                'points' => (int) $a->point_balance, 'stamps' => (int) $a->stamp_count,
            ])->all();
    }

    public static function earned(Order $order): array
    {
        $rows = LoyaltyTransaction::query()->where('company_id', $order->company_id)
            ->where('order_id', $order->id)->where('type', LoyaltyTransaction::TYPE_EARN)->get();

        return ['points' => (int) $rows->sum('points_delta'), 'stamps' => (int) $rows->sum('stamps_delta')];
    }
}
