<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use App\Actions\Qr\AllocateQrRoundAcceptedSequenceAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Actions\Tables\AppendTableSessionEventAction;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Actions\Tables\ResolveStaffSeatingAction;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TabletOrder;
use App\Models\TabletOrderEvent;
use App\Support\Money;
use App\Support\Pricing\BillMoney;
use App\Support\Staff\AuthorizationGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LAUNCH-P6 Part A items 3–4 — what staff do with a tablet order on a till or
 * a handheld (tester calls 9–12):
 *
 *  - list: pending (not yet sent) and unpaid tablet orders of the branch;
 *  - take / take over: the first take wins under a row lock; another staff
 *    member may take over (audited); every other device shows "Taken by";
 *  - send to the kitchen: Quick / To go — recorded (who, when) and a kitchen
 *    round is written for the accepted-round feed and print claims; the
 *    order stays on the list as Unpaid until it is paid or cancelled and is
 *    never auto-cancelled. Dine in — the pending round is confirmed with
 *    the staff round review (it joins the table's bill);
 *  - approve / reject the customer's points request: `loyalty.redeem` (the
 *    position tick or an approver PIN proof) and the table redemption's
 *    limits (3 per customer per Muscat day, 10 per approver per shift, the
 *    balance less what other unpaid bills reserve, a positive amount left).
 *    A rejection leaves the full amount to pay.
 *
 * Send, approve and reject act for the staff member who has taken the
 * order; an untaken order is taken by whoever acts first.
 */
final class TabletOrderStaffAction
{
    public function __construct(
        private readonly ConfirmStaffRoundAction $confirm,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
        private readonly PresentQrPendingOrderAction $present,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
        private readonly AppendTableSessionEventAction $journal,
        private readonly AuthorizationGate $gate,
    ) {}

    /**
     * Pending + unpaid rows of the device's branch, oldest first (at most 200).
     *
     * @return Collection<int, TabletOrder>
     */
    public function rows(Device $device, bool $unpaidOnly = false): Collection
    {
        return $this->listed($device, $unpaidOnly)->orderBy('pos_tablet_orders.id')->limit(200)->get();
    }

    /**
     * Attention keys (tester call 10): a tablet order rings on every staff
     * device until someone takes it (or it is sent / paid / cancelled).
     *
     * @return list<string>
     */
    public function attentionKeys(Device $device): array
    {
        return $this->listed($device, false)
            ->whereNull('pos_tablet_orders.taken_by_staff_id')->whereNull('pos_tablet_orders.sent_to_kitchen_at')
            ->where(fn (Builder $q) => $q->where(fn (Builder $d) => $d->where('pos_tablet_orders.order_type', 'dine_in')
                ->where('r.status', QrOrderRound::STATUS_PENDING_CONFIRMATION))->orWhere(fn (Builder $c) => $c
                ->whereIn('pos_tablet_orders.order_type', ['quick', 'to_go'])->whereIn('o.status', TabletOrderPresenter::UNPAID)))
            ->orderBy('pos_tablet_orders.id')->pluck('pos_tablet_orders.uuid')
            ->map(static fn ($uuid): string => 'tablet:'.$uuid)->all();
    }

    /** @return Builder<TabletOrder> */
    private function listed(Device $device, bool $unpaidOnly): Builder
    {
        $unpaid = TabletOrderPresenter::UNPAID;

        return TabletOrder::query()->select('pos_tablet_orders.*')
            ->join('pos_orders as o', 'o.id', '=', 'pos_tablet_orders.order_id')
            ->leftJoin('pos_qr_order_rounds as r', 'r.id', '=', 'pos_tablet_orders.round_id')
            ->where('pos_tablet_orders.company_id', (int) $device->company_id)
            ->where('pos_tablet_orders.branch_id', (int) $device->branch_id)
            ->where('o.company_id', (int) $device->company_id)->where('o.branch_id', (int) $device->branch_id)
            ->where(function (Builder $q) use ($unpaid, $unpaidOnly): void {
                $q->where(function (Builder $counter) use ($unpaid, $unpaidOnly): void {
                    $counter->whereIn('pos_tablet_orders.order_type', ['quick', 'to_go'])
                        ->where(function (Builder $state) use ($unpaid, $unpaidOnly): void {
                            $state->whereIn('o.status', $unpaid);
                            if (! $unpaidOnly) {
                                // Cash first, then the kitchen: listed until sent.
                                $state->orWhere(fn (Builder $p) => $p->where('o.status', Order::STATUS_PAID)
                                    ->whereNull('pos_tablet_orders.sent_to_kitchen_at'));
                            }
                        });
                })->orWhere(function (Builder $dine) use ($unpaid): void {
                    $dine->where('pos_tablet_orders.order_type', 'dine_in')->whereIn('o.status', $unpaid)
                        ->whereIn('r.status', [QrOrderRound::STATUS_PENDING_CONFIRMATION, QrOrderRound::STATUS_ACCEPTED]);
                });
            });
    }

    /** @return array<string, mixed> */
    public function take(Device $device, int $staffId, string $uuid, bool $takeOver): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid, $takeOver): array {
            $row = $this->locked($device, $uuid);
            $this->assertOpen($row);
            $outcome = $this->claim($row, $device, $staffId, $takeOver);

            return ['outcome' => $outcome, 'order' => $this->present($row)];
        });
    }

    /** @return array<string, mixed> */
    public function send(Device $device, int $staffId, string $uuid): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid): array {
            $row = $this->locked($device, $uuid);
            if ($row->sent_to_kitchen_at !== null) {
                return ['outcome' => 'replayed', 'order' => $this->present($row)];
            }
            $this->assertOpen($row);
            $this->claim($row, $device, $staffId, false);
            $now = now();
            if ($row->isDineIn()) {
                $round = QrOrderRound::query()->whereKey($row->round_id)->first();
                $seating = $round?->table_session_id === null ? null : TableSession::query()->whereKey($round->table_session_id)
                    ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)->first();
                if ($round === null || $seating === null) {
                    throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
                }
                try {
                    $result = $this->confirm->handle($device, (string) $seating->uuid, (int) $round->id, markTablet: false);
                } catch (QrDineInException $exception) {
                    throw new TabletOrderException($exception->codeName, $exception->httpStatus, $exception->getMessage());
                }
                if (! in_array($result['outcome'], ['accepted', 'replayed'], true) || $result['round_status'] !== QrOrderRound::STATUS_ACCEPTED) {
                    throw new TabletOrderException('tablet_round_not_sendable', 409, 'Reopen the table bill before sending this round.',
                        ['outcome' => $result['outcome']]);
                }
            } else {
                $order = Order::query()->whereKey($row->order_id)->where('company_id', $device->company_id)
                    ->where('branch_id', $device->branch_id)->lockForUpdate()->first();
                if ($order === null || in_array($order->status, [Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_COMBINED], true)) {
                    throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
                }
                // The kitchen round: the accepted-round feed and the print
                // claim admit a sent Quick / To go tablet order through it.
                $round = QrOrderRound::query()->create([
                    'qr_session_id' => null, 'table_session_id' => null, 'order_id' => (int) $order->id,
                    'round_no' => (int) QrOrderRound::query()->where('order_id', $order->id)->max('round_no') + 1,
                    'status' => QrOrderRound::STATUS_ACCEPTED, 'client_request_id' => (string) $row->uuid,
                    'priced_lines' => $row->kitchen_lines ?? [], 'confirm_payload' => null, 'needs_review' => false,
                    'subtotal_baisas' => (int) $row->subtotal_baisas, 'tax_baisas' => (int) $row->tax_baisas,
                    'total_baisas' => (int) $row->total_baisas, 'submitted_at' => $row->submitted_at ?? $now,
                    'resolved_at' => $now, 'resolved_by_device_id' => (int) $device->id, 'accepted_seq' => null,
                ]);
                $row->round_id = (int) $round->id;
            }
            $row->fill(['sent_to_kitchen_at' => $now, 'sent_by_staff_id' => $staffId, 'sent_by_device_id' => (int) $device->id])->save();
            TabletOrderEvent::record($row, 'sent_to_kitchen', $staffId, (int) $device->id, ['round_id' => (int) $row->round_id]);
            if (! $row->isDineIn()) {
                // As late as possible: the sequence's lock is held to commit.
                QrOrderRound::query()->whereKey($row->round_id)->update(['accepted_seq' => $this->acceptedSequence->next()]);
            }

            return ['outcome' => 'sent', 'order' => $this->present($row->fresh())];
        });
    }

    /**
     * @param  array<string, mixed>  $payload  {client_request_id, authorization}
     * @return array<string, mixed>
     */
    public function approve(Device $device, int $staffId, string $uuid, array $payload, ?string $staffToken): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid, $payload, $staffToken): array {
            $row = $this->locked($device, $uuid);
            if ($row->redeem_status === TabletOrder::REDEEM_APPROVED) {
                return ['outcome' => 'replayed', 'order' => $this->present($row)];
            }
            $this->assertRequested($row);
            $this->assertOpen($row);
            $this->claim($row, $device, $staffId, false);
            $reward = TabletLoyalty::reward((int) $row->company_id, (int) $row->redeem_rule_id);
            if ($reward === null) {
                throw new TabletOrderException('loyalty_rule_unsupported', 409, 'This reward is no longer available. Reject the request.');
            }
            $blocks = (int) $row->redeem_blocks;
            $amount = $reward['value_baisas'] * $blocks;
            $requestId = (string) $payload['client_request_id'];

            // loyalty.redeem — the acting staff member's position tick, or an
            // approver's PIN proof for THIS tablet order, amount and request.
            $outcome = $this->gate->evaluate($device, [
                'action' => 'loyalty.redeem', 'subject_type' => 'tablet_order', 'subject_uuid' => (string) $row->uuid,
                'amount_baisas' => $amount, 'actor_staff_id' => $staffId, 'staff_token' => $staffToken,
                'expected_ref' => $requestId, 'max_age_seconds' => AuthorizationGate::ONLINE_MAX_AGE_SECONDS,
                'client_event_id' => $requestId, 'at' => now(),
            ], AuthorizationGate::block($payload['authorization'] ?? null), true);
            if (! $outcome->authorized()) {
                throw new TabletOrderException($outcome->refusalCode(), 403, $outcome->refusalCode() === 'approval_required'
                    ? 'A manager must approve this.' : 'The manager approval could not be verified. Approve again.',
                    ['reason' => $outcome->reason]);
            }
            $approver = (int) $outcome->approvedBy();
            $intent = ['rule_id' => (int) $row->redeem_rule_id, 'blocks' => $blocks, 'approved_by_staff_id' => $approver,
                'authorized_by' => (string) DB::table('pos_staff')->where('id', $approver)->value('name')];

            $result = $row->isDineIn()
                ? $this->redeemOnTable($device, $row, $intent, $requestId, $staffId)
                : $this->redeemOnOrder($device, $row, $intent, $amount);

            $row->fill([
                'redeem_status' => TabletOrder::REDEEM_APPROVED, 'redeem_units' => (int) ($result['points'] + $result['stamps']),
                'redeem_amount_baisas' => (int) $result['amount_baisas'], 'redeem_discount_row_id' => (int) $result['discount_row_id'],
                'redeem_resolved_by_staff_id' => $approver, 'redeem_resolved_by_device_id' => (int) $device->id,
                'redeem_resolved_at' => now(),
            ])->save();
            TabletOrderEvent::record($row, 'redeem_approved', $staffId, (int) $device->id, [
                'approver_staff_id' => $approver, 'result' => $outcome->result, 'rule_id' => (int) $row->redeem_rule_id,
                'blocks' => $blocks, 'units' => (int) $row->redeem_units, 'amount_baisas' => (int) $row->redeem_amount_baisas,
                'discount_row_id' => (int) $row->redeem_discount_row_id, 'client_request_id' => $requestId,
            ]);

            return ['outcome' => 'approved', 'order' => $this->present($row->fresh())];
        });
    }

    /** @return array<string, mixed> */
    public function reject(Device $device, int $staffId, string $uuid): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid): array {
            $row = $this->locked($device, $uuid);
            if ($row->redeem_status === TabletOrder::REDEEM_REJECTED) {
                return ['outcome' => 'replayed', 'order' => $this->present($row)];
            }
            $this->assertRequested($row);
            $this->claim($row, $device, $staffId, false);
            $row->fill(['redeem_status' => TabletOrder::REDEEM_REJECTED, 'redeem_resolved_by_staff_id' => $staffId,
                'redeem_resolved_by_device_id' => (int) $device->id, 'redeem_resolved_at' => now()])->save();
            TabletOrderEvent::record($row, 'redeem_rejected', $staffId, (int) $device->id, ['rule_id' => (int) $row->redeem_rule_id,
                'blocks' => (int) $row->redeem_blocks]);

            return ['outcome' => 'rejected', 'order' => $this->present($row->fresh())];
        });
    }

    /**
     * Quick / To go: the held order's own header takes the redemption (the
     * table slot rows, so payment redeems the points exactly as on a bill).
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function redeemOnOrder(Device $device, TabletOrder $row, array $intent, int $amount): array
    {
        $order = Order::query()->whereKey($row->order_id)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->lockForUpdate()->first();
        if ($order === null || ! in_array($order->status, [Order::STATUS_HELD, Order::STATUS_OPEN], true)
            || ! $this->present->hasNoChargeProvenance($order)) {
            throw new TabletOrderException('tablet_order_paid', 409, 'Points can be used only before payment.');
        }
        if ($order->customer_id === null || (int) $order->customer_id !== (int) $row->customer_id) {
            throw new TabletOrderException('redeem_customer_mismatch', 409, 'The order no longer belongs to this customer.');
        }
        $inclusive = (bool) $order->prices_include_tax;
        $net = BillMoney::net((int) $row->total_baisas, (int) $row->tax_baisas, $inclusive);
        $result = $this->tableRedeem(fn (): array => TableLoyaltyDiscount::redeem($order, $intent, $net));
        $header = $this->totals->header(['subtotal' => (int) $row->subtotal_baisas, 'tax' => (int) $row->tax_baisas,
            'total' => (int) $row->total_baisas, 'loyalty' => TableLoyaltyDiscount::amount($order), 'manual' => 0, 'comp' => 0,
            'inclusive' => (int) $inclusive]);
        unset($header['comp_total']);
        $order->update($header);
        if ((int) $result['amount_baisas'] !== $amount) {
            throw new \RuntimeException('Tablet redeem amount drifted while approving.');
        }

        return $result;
    }

    /**
     * Dine in: once the round is on the bill, the redemption is the table
     * bill's own (the staff adjustment's locks, checks and journal row, so
     * the per-customer and per-staff limits count it).
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function redeemOnTable(Device $device, TabletOrder $row, array $intent, string $requestId, int $staffId): array
    {
        $round = QrOrderRound::query()->whereKey($row->round_id)->first();
        if ($round?->status !== QrOrderRound::STATUS_ACCEPTED || $row->sent_to_kitchen_at === null) {
            throw new TabletOrderException('tablet_round_not_sent', 409, 'Send the round to the kitchen before using the points.');
        }
        $seating = TableSession::query()->whereKey($round->table_session_id)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->first();
        if ($seating === null) {
            throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
        }
        $payload = [
            'seating_key' => (string) ($seating->client_request_id !== null && Str::isUuid((string) $seating->client_request_id)
                ? $seating->client_request_id : $seating->uuid),
            'table_id' => (int) $seating->table_id, 'queued_offline' => false, 'staff_id' => $staffId,
            'client_request_id' => $requestId,
            'adjustment' => ['kind' => 'loyalty', 'mode' => 'redeem', 'rule_id' => (int) $intent['rule_id'], 'blocks' => (int) $intent['blocks']],
        ];
        try {
            return $this->resolver->locked($device, $payload, 'adjust', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $seating, $row, $intent, $requestId): array {
                $seat = $this->resolver->resolve($seatings, $payload['seating_key'], (string) $seating->uuid)['primary'];
                $order = $seat === null ? null : $orders->get((int) $seat->order_id);
                if ($order === null || (int) $order->id !== (int) $row->order_id || (int) $order->table_session_id !== (int) $seat->id) {
                    throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
                }
                if ($order->status !== Order::STATUS_OPEN || $seat->status !== TableSession::STATUS_OPEN
                    || $seat->billing_at !== null || $this->present->charge($order, now()) !== 'none') {
                    throw new TabletOrderException('bill_reserved', 409, 'Reopen the bill and resolve its payment result before using points.');
                }
                if ($order->customer_id === null || (int) $order->customer_id !== (int) $row->customer_id) {
                    throw new TabletOrderException('redeem_customer_mismatch', 409, 'The table bill belongs to another customer.');
                }
                $this->baseline->assertReadyForCharge($order);
                $a = $this->totals->amounts($order);
                $net = RefreshQrOrderTotalsAction::net($a);
                if (! $this->totals->matchesHeader($order, $a) || $net < 1) {
                    throw new TabletOrderException('adjustment_exceeds_bill', 409, 'The frozen bill must be consistent and have a positive balance.');
                }
                $proof = $this->tableRedeem(fn (): array => TableLoyaltyDiscount::redeem($order, $intent, $net - $a['manual'] - $a['comp']));
                $this->totals->handle($order);
                $ack = ['client_request_id' => $requestId, 'kind' => 'loyalty', 'mode' => 'redeem',
                    'grand_total_baisas' => Money::toBaisas($order->grand_total)];
                $this->journal->handle($seat, 'adjusted', ['action' => 'bill_adjusted', 'kind' => 'loyalty', 'mode' => 'redeem',
                    'client_request_id' => $requestId, 'order_uuid' => $order->uuid] + $proof + [
                        'authorized_by' => $intent['authorized_by'], 'approved_by_staff_id' => (int) $intent['approved_by_staff_id'],
                        'origin' => 'customer_tablet', 'tablet_order_uuid' => (string) $row->uuid,
                        'request_hash' => hash('sha256', 'tablet-redeem:'.$row->uuid.':'.$requestId), 'result' => $ack,
                    ], (int) $device->id);

                return $proof;
            });
        } catch (QrDineInException $exception) {
            throw new TabletOrderException($exception->codeName, $exception->httpStatus, $exception->getMessage());
        }
    }

    /**
     * @param  \Closure(): array<string, mixed>  $redeem
     * @return array<string, mixed>
     */
    private function tableRedeem(\Closure $redeem): array
    {
        try {
            return $redeem();
        } catch (QrDineInException $exception) {
            throw new TabletOrderException($exception->codeName, $exception->httpStatus, $exception->getMessage());
        }
    }

    private function locked(Device $device, string $uuid): TabletOrder
    {
        $row = Str::isUuid($uuid) ? TabletOrder::query()->where('uuid', $uuid)
            ->where('company_id', (int) $device->company_id)->where('branch_id', (int) $device->branch_id)
            ->lockForUpdate()->first() : null;
        if ($row === null) {
            throw new TabletOrderException('tablet_order_not_found', 404, 'The tablet order was not found in this branch.');
        }

        return $row;
    }

    private function assertOpen(TabletOrder $row): void
    {
        $order = Order::query()->whereKey($row->order_id)->first();
        $round = $row->round_id === null ? null : QrOrderRound::query()->whereKey($row->round_id)->first();
        $closed = $order === null || in_array($order->status, [Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_COMBINED], true)
            || $round?->status === QrOrderRound::STATUS_REJECTED
            || (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true) && $row->sent_to_kitchen_at !== null);
        if ($closed) {
            throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
        }
    }

    private function assertRequested(TabletOrder $row): void
    {
        if ($row->redeem_status === null) {
            throw new TabletOrderException('redeem_not_requested', 409, 'This order has no points request.');
        }
        if ($row->redeem_status !== TabletOrder::REDEEM_REQUESTED) {
            throw new TabletOrderException('redeem_already_resolved', 409, 'This points request was already answered.');
        }
    }

    /**
     * Take (or keep) the row for this staff member. Another member's take is
     * refused unless $takeOver (audited with the previous holder).
     */
    private function claim(TabletOrder $row, Device $device, int $staffId, bool $takeOver): string
    {
        if ($row->taken_by_staff_id !== null && (int) $row->taken_by_staff_id === $staffId) {
            return 'already_yours';
        }
        if ($row->taken_by_staff_id !== null && ! $takeOver) {
            throw new TabletOrderException('tablet_order_taken', 409, 'Another staff member has taken this order.', [
                'taken_by' => ['staff_id' => (int) $row->taken_by_staff_id,
                    'name' => DB::table('pos_staff')->where('id', $row->taken_by_staff_id)->value('name'),
                    'device_id' => $row->taken_by_device_id === null ? null : (int) $row->taken_by_device_id,
                    'at' => $row->taken_at?->toIso8601String()],
            ]);
        }
        $previous = $row->taken_by_staff_id === null ? null
            : ['previous_staff_id' => (int) $row->taken_by_staff_id, 'previous_device_id' => $row->taken_by_device_id === null ? null : (int) $row->taken_by_device_id];
        $row->fill(['taken_by_staff_id' => $staffId, 'taken_by_device_id' => (int) $device->id, 'taken_at' => now()])->save();
        TabletOrderEvent::record($row, $previous === null ? 'taken' : 'taken_over', $staffId, (int) $device->id, $previous ?? []);

        return $previous === null ? 'taken' : 'taken_over';
    }

    /** @return array<string, mixed> */
    private function present(TabletOrder $row): array
    {
        return app(TabletOrderPresenter::class)->forStaff(collect([$row]))[0];
    }
}
