<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TabletOrder;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** LAUNCH-P6 — the two views of a tablet order: the tablet's and the staff's. */
final class TabletOrderPresenter
{
    public const UNPAID = [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_KITCHEN];

    /**
     * What the tablet shows after ordering ("Thank you … Ready in about N
     * min", the order number for Quick / To go). Never a staff or customer
     * name, never another order.
     *
     * @return array<string, mixed>
     */
    public function forTablet(TabletOrder $row): array
    {
        $order = Order::query()->whereKey($row->order_id)->where('company_id', $row->company_id)->first();
        $table = $row->table_id === null ? null : Table::withTrashed()->whereKey($row->table_id)
            ->where('company_id', $row->company_id)->first(['uuid', 'label']);

        return [
            'tablet_order_uuid' => (string) $row->uuid,
            'order_uuid' => (string) $order?->uuid,
            'order_type' => (string) $row->order_type,
            'order_number' => $row->isDineIn() ? null : TabletOrder::orderNumber($order?->temp_reference),
            'table' => $table === null ? null : ['uuid' => (string) $table->uuid, 'name' => (string) $table->label],
            'ready_in_minutes' => $row->ready_in_minutes,
            'status' => 'waiting_for_staff',
            'payment' => (string) $row->payment_choice,
            'total_baisas' => (int) $row->total_baisas,
            'redeem' => $row->redeem_status === null ? null : [
                'status' => (string) $row->redeem_status, 'rule_id' => $row->redeem_rule_id === null ? null : (int) $row->redeem_rule_id,
                'blocks' => (int) $row->redeem_blocks,
            ],
        ];
    }

    /**
     * The staff list rows (till / handheld), branch-scoped by the caller.
     *
     * @param  Collection<int, TabletOrder>  $rows
     * @return list<array<string, mixed>>
     */
    public function forStaff(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $companyId = (int) $rows->first()->company_id;
        $orders = Order::query()->where('company_id', $companyId)->whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');
        $rounds = QrOrderRound::query()->whereIn('id', $rows->pluck('round_id')->filter())->get()->keyBy('id');
        $tables = Table::withTrashed()->where('company_id', $companyId)->whereIn('id', $rows->pluck('table_id')->filter())->get()->keyBy('id');
        $seatings = TableSession::query()->where('company_id', $companyId)
            ->whereIn('id', $rounds->pluck('table_session_id')->filter())->get()->keyBy('id');
        $staffIds = $rows->flatMap(static fn (TabletOrder $row): array => [$row->taken_by_staff_id, $row->sent_by_staff_id, $row->redeem_resolved_by_staff_id])
            ->filter()->unique()->values();
        $staff = DB::table('pos_staff')->where('company_id', $companyId)->whereIn('id', $staffIds)->pluck('name', 'id');
        $phones = Customer::withTrashed()->where('company_id', $companyId)->whereIn('id', $rows->pluck('customer_id')->filter())->pluck('phone', 'id');
        $rules = LoyaltyRule::withTrashed()->where('company_id', $companyId)->whereIn('id', $rows->pluck('redeem_rule_id')->filter())->get()->keyBy('id');

        return $rows->map(function (TabletOrder $row) use ($orders, $rounds, $tables, $seatings, $staff, $phones, $rules): array {
            $order = $orders->get($row->order_id);
            $round = $row->round_id === null ? null : $rounds->get($row->round_id);
            $table = $row->table_id === null ? null : $tables->get($row->table_id);
            $person = static fn (?int $id, ?int $deviceId, $at) => $id === null && $at === null ? null : [
                'staff_id' => $id, 'name' => $id === null ? null : $staff->get($id),
                'device_id' => $deviceId, 'at' => $at?->toIso8601String(),
            ];
            $paid = in_array($order?->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true);
            $closed = $order === null || in_array($order->status, [Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_COMBINED], true)
                || $round?->status === QrOrderRound::STATUS_REJECTED;
            // A dine-in round staff confirmed elsewhere (the table board) is sent too.
            $sent = $row->sent_to_kitchen_at !== null || ($row->isDineIn() && $round?->status === QrOrderRound::STATUS_ACCEPTED);

            return [
                'tablet_order_uuid' => (string) $row->uuid,
                'order_uuid' => (string) $order?->uuid,
                'order_type' => (string) $row->order_type,
                'source' => 'customer_tablet',
                'order_status' => $order?->status,
                'state' => $closed ? 'closed' : ($sent ? 'sent' : 'pending'),
                'paid' => $paid,
                'unpaid' => ! $paid && ! $closed,
                'order_number' => $row->isDineIn() ? null : TabletOrder::orderNumber($order?->temp_reference),
                'temp_reference' => $order?->temp_reference,
                'table' => $table === null ? null : ['id' => (int) $table->id, 'uuid' => (string) $table->uuid, 'name' => (string) $table->label],
                'table_session_uuid' => $round?->table_session_id === null ? null : $seatings->get($round->table_session_id)?->uuid,
                'round_id' => $round === null ? null : (int) $round->id,
                'round_status' => $round?->status,
                'lines' => $row->isDineIn() ? ($round?->priced_lines ?? []) : ($row->kitchen_lines ?? []),
                'total_baisas' => (int) $row->total_baisas,
                'grand_total_baisas' => $order === null ? null : Money::toBaisas($order->grand_total),
                'prices_include_tax' => (bool) $order?->prices_include_tax,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'phone_masked' => $row->customer_id === null ? null : TabletLoyalty::masked($phones->get($row->customer_id)),
                'payment' => (string) $row->payment_choice,
                'redeem' => $this->redeem($row, $rules->get($row->redeem_rule_id), $person, $order),
                'taken_by' => $row->taken_by_staff_id === null ? null : $person((int) $row->taken_by_staff_id,
                    $row->taken_by_device_id === null ? null : (int) $row->taken_by_device_id, $row->taken_at),
                'sent_to_kitchen' => $sent ? $person($row->sent_by_staff_id === null ? null : (int) $row->sent_by_staff_id,
                    $row->sent_by_device_id === null ? null : (int) $row->sent_by_device_id, $row->sent_to_kitchen_at) : null,
                'ready_in_minutes' => $row->ready_in_minutes,
                'submitted_at' => $row->submitted_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function redeem(TabletOrder $row, ?LoyaltyRule $rule, \Closure $person, ?Order $order): ?array
    {
        if ($row->redeem_status === null) {
            return null;
        }
        $reward = $rule === null ? null : TabletLoyalty::reward((int) $row->company_id, (int) $rule->id);
        $blocks = (int) $row->redeem_blocks;
        $approved = $row->redeem_status === TabletOrder::REDEEM_APPROVED;
        $settled = in_array($row->redeem_status, [TabletOrder::REDEEM_APPROVED, TabletOrder::REDEEM_SUPERSEDED], true);
        // Fix order 1 (F-2) — an approved request shows what the bill's points
        // slot takes NOW (0 once the slot is gone); `approved_*` keep what was
        // approved.
        $slot = $approved && $order !== null ? TableLoyaltyDiscount::current($order) : null;
        $live = $slot !== null && (int) $slot['discount_row_id'] === (int) $row->redeem_discount_row_id;

        return [
            'status' => (string) $row->redeem_status,
            'rule_id' => $row->redeem_rule_id === null ? null : (int) $row->redeem_rule_id,
            'rule_name' => $rule?->name,
            'kind' => $reward['kind'] ?? ($rule?->type === 'visit_based' ? 'stamps' : 'points'),
            'blocks' => $blocks,
            // Requested: what approving it now would take (the amount the
            // approver's proof names); approved: what the bill's slot takes
            // now; superseded / rejected: nothing.
            'units' => $settled || $row->redeem_status === TabletOrder::REDEEM_REJECTED
                ? ($live ? (int) $slot['points'] + (int) $slot['stamps'] : 0)
                : ($reward === null ? null : $reward['unit'] * $blocks),
            'amount_baisas' => $settled || $row->redeem_status === TabletOrder::REDEEM_REJECTED
                ? ($live ? (int) $slot['amount_baisas'] : 0)
                : ($reward === null ? null : $reward['value_baisas'] * $blocks),
            'approved_units' => $settled ? (int) $row->redeem_units : null,
            'approved_amount_baisas' => $settled ? (int) $row->redeem_amount_baisas : null,
            'available' => $reward !== null,
            'resolved_by' => $row->redeem_resolved_at === null ? null : $person($row->redeem_resolved_by_staff_id === null ? null
                : (int) $row->redeem_resolved_by_staff_id, $row->redeem_resolved_by_device_id === null ? null
                : (int) $row->redeem_resolved_by_device_id, $row->redeem_resolved_at),
        ];
    }
}
