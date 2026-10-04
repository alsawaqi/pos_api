<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderDiscount;
use App\Models\TableSessionEvent;
use App\Support\Money;
use Illuminate\Support\Arr;

/** Device detail includes staff context; public totals never include identity/reasons. */
final class TableBillAdjustmentView
{
    public static function deviceFields(Order $order): array
    {
        $rows = app(RefreshQrOrderTotalsAction::class)->manualRows($order)->orderBy('id')->get();

        $loyalty = TableLoyaltyDiscount::amount($order);

        return ($loyalty > 0 ? ['loyalty_discount_baisas' => $loyalty] : []) + ['manual_discount_baisas' => $rows->sum(fn ($row): int => Money::toBaisas($row->amount)),
            'discount_sources' => OrderDiscount::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn ($row): array => ['source' => in_array($row->amount_type_snapshot, TableLoyaltyDiscount::TYPES, true) ? 'table_loyalty_redeem' : (in_array($row->amount_type_snapshot, RefreshQrOrderTotalsAction::MANUAL_TYPES, true) ? 'manual' : ($row->offer_id !== null ? 'offer' : ($row->order_item_id !== null ? 'line' : 'order'))),
                    'order_item_id' => $row->order_item_id === null ? null : (int) $row->order_item_id,
                    'discount_id' => $row->discount_id === null ? null : (int) $row->discount_id,
                    'offer_id' => $row->offer_id === null ? null : (int) $row->offer_id,
                    'name' => $row->name_snapshot, 'amount_type' => $row->amount_type_snapshot,
                    'amount_baisas' => Money::toBaisas($row->amount)])->all(),
            'discounts' => $rows->map(fn ($row): array => ['name' => $row->name_snapshot,
                'amount_baisas' => Money::toBaisas($row->amount), 'amount_type' => $row->amount_type_snapshot, 'reason' => $row->reason])->all()];
    }

    public static function detailFields(Order $order): array
    {
        $a = app(RefreshQrOrderTotalsAction::class)->amounts($order);
        $state = ['discount' => null, 'comp' => null];
        foreach (['discount' => 'manual', 'comp' => 'comp'] as $kind => $amountKey) {
            $event = TableSessionEvent::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('event_type', 'adjusted')->where('payload->order_uuid', $order->uuid)
                ->where('payload->kind', $kind)->orderByDesc('id')->first();
            if ($event === null || $a[$amountKey] <= 0) {
                continue;
            }
            $state[$kind] = Arr::only($event->payload, ['mode', 'percent_bp', 'basis_baisas', 'comp_reason_id', 'order_item_id', 'qty'])
                + ['amount_baisas' => $a[$amountKey], 'stale' => ($kind === 'comp' || isset($event->payload['percent_bp']))
                    && ($event->payload['basis_baisas'] ?? null) !== RefreshQrOrderTotalsAction::net($a)];
            if ($kind === 'discount') {
                $row = app(RefreshQrOrderTotalsAction::class)->manualRows($order)->where('amount', '>', 0)->orderByDesc('id')->first();
                $state[$kind] += ['name' => $row?->name_snapshot, 'reason' => $row?->reason];
            } else {
                $row = OrderComp::query()->where('order_id', $order->id)->where('amount', '>', 0)->orderByDesc('id')->first();
                $state[$kind] += ['reason_name' => $row?->reason_name_snapshot, 'note' => $row?->note];
            }
        }
        $state['loyalty'] = TableLoyaltyDiscount::current($order);
        $customer = $order->customer_id === null ? null : Customer::withTrashed()->whereKey($order->customer_id)
            ->where('company_id', $order->company_id)->first();

        // LAUNCH-P5 fix order 1 — the exact base the server applies a table
        // discount to (pre-tax net of the accepted rounds, in the bill's tax
        // mode): a percent discount = round(basis × percent_bp / 10000), half
        // away from zero — the amount_baisas of its approval proof.
        return ['adjustment_state' => $state, 'adjustment_basis_baisas' => RefreshQrOrderTotalsAction::net($a),
            'customer' => $customer === null ? null : [
                'id' => (int) $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]];
    }

    public static function publicTotals(Order $order): array
    {
        $fields = self::deviceFields($order);
        $result = Arr::only($fields, ['manual_discount_baisas', 'loyalty_discount_baisas']);
        foreach (['subtotal', 'discount_total', 'comp_total', 'tax_total', 'grand_total'] as $field) {
            $result[$field.'_baisas'] = Money::toBaisas($order->$field ?? 0);
        }

        return $result;
    }

    public static function refuseUnsupported(Order $order): void
    {
        if (TableLoyaltyDiscount::rows($order)->exists()
            || app(RefreshQrOrderTotalsAction::class)->manualRows($order)->exists()
            || TableSessionEvent::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('event_type', 'adjusted')->where('payload->order_uuid', $order->uuid)
                ->where('payload->kind', 'comp')->exists()) {
            throw AdjustTableBillAction::refusal('adjusted_bill_not_supported',
                'This adjusted bill cannot use legacy draft recovery or combine. Keep its original bill and settle it normally.');
        }
    }
}
