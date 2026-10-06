<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\FreezeQrRoundLinesAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrPricingLoadResult;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TabletOrderEvent;
use App\Support\Catalogue\CookingTime;
use App\Support\Pricing\BillMoney;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P6 fix order 1 (F-8, owner decision 2026-10-06) — staff edit a
 * tablet order before it is sent to the kitchen:
 * `PUT device/tablet-orders/{uuid}/lines` { client_request_id, lines }.
 *
 *  - the full new list, server-priced exactly like the tablet's submit
 *    (customer menu, the order's type, no client price, no free-text note;
 *    sold-out / unavailable lines named, 409 tablet_lines_unavailable);
 *  - Quick / To go: the held order's items and their discounts are replaced
 *    and its header recalculated (an approved points slot stays and is
 *    re-applied; if it would no longer leave something to pay, 409
 *    redeem_exceeds_order — staff reject the points first);
 *  - Dine in: the PENDING round's priced lines and stored confirm payload are
 *    replaced (the bill itself is untouched until staff send it);
 *  - refused once sent (409 tablet_order_sent), paid (409 tablet_order_paid)
 *    or closed (409 tablet_order_closed); the taker rule applies (an
 *    untaken order is taken by whoever edits);
 *  - idempotent on client_request_id (a repeat answers `replayed`);
 *  - ready_in_minutes is recomputed; an `edited` event records the staff
 *    member and the lines before and after.
 *
 * Lock order (fix order 1 F-7): the tablet row first, then the order (Quick /
 * To go) or the round (dine in).
 */
final class TabletOrderEditAction
{
    public function __construct(
        private readonly TabletOrderStaffAction $staff,
        private readonly LoadQrPricingInputAction $pricing,
        private readonly AppendQrPricedLinesAction $append,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly PresentQrPendingOrderAction $present,
        private readonly RefreshQrOrderTotalsAction $totals,
    ) {}

    /**
     * @param  array{client_request_id: string, lines: list<array<string, mixed>>}  $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, int $staffId, string $uuid, array $payload): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid, $payload): array {
            $row = $this->staff->locked($device, $uuid);
            $requestId = (string) $payload['client_request_id'];
            $done = TabletOrderEvent::query()->where('tablet_order_id', $row->id)->where('event_type', 'edited')->get()
                ->first(static fn (TabletOrderEvent $event): bool => ($event->payload['client_request_id'] ?? null) === $requestId);
            if ($done !== null) {
                return ['outcome' => 'replayed', 'order' => $this->staff->present($row, $device)];
            }
            $this->staff->assertOpen($row);
            $round = $row->round_id === null ? null : QrOrderRound::query()->whereKey($row->round_id)->first();
            if ($row->sent_to_kitchen_at !== null || ($row->isDineIn() && $round?->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION)) {
                throw new TabletOrderException('tablet_order_sent', 409, 'The order was sent to the kitchen; cancel lines on the bill instead.');
            }
            $order = Order::query()->whereKey($row->order_id)->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                // A table bill is not locked here (its writers lock the table
                // graph first); a pending round only changes its own row.
                ->when(! $row->isDineIn(), fn ($query) => $query->lockForUpdate())->first();
            if ($order === null) {
                throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
            }
            // Fix order 2 (F-10) — while staff hold the cash claim (or its
            // outcome is approved / uncertain) nobody may change what is being
            // paid; a claim released as cancelled / declined with no payment
            // goes back to the counter and the order may change again.
            if (! $row->isDineIn() && Order::query()->whereKey($order->id)->withLiveClaim(now())->exists()) {
                throw new TabletOrderException('tablet_order_being_paid', 409, 'Staff are taking the payment for this order; cancel the payment first.');
            }
            if (! $row->isDineIn() && $order->status === Order::STATUS_AWAITING_PAYMENT
                && in_array($order->charge_outcome, [Order::CHARGE_OUTCOME_CANCELLED, Order::CHARGE_OUTCOME_DECLINED], true)
                && ! $order->payments()->exists()) {
                $order->update(['status' => Order::STATUS_HELD] + array_fill_keys(PresentQrPendingOrderAction::CHARGE_FIELDS, null));
            }
            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true)
                || (! $row->isDineIn() && (! in_array($order->status, [Order::STATUS_HELD, Order::STATUS_OPEN], true)
                    || ! $this->present->hasNoChargeProvenance($order)))) {
                throw new TabletOrderException('tablet_order_paid', 409, 'The order is paid or being paid; it can no longer change.');
            }
            $this->staff->claim($row, $device, $staffId, false);

            $companyId = (int) $device->company_id;
            $branchId = (int) $device->branch_id;
            $lines = SubmitTabletOrderAction::withoutNotes($payload['lines']);
            $now = now();
            $at = DateTimeImmutable::createFromInterface($now);
            $classified = $this->pricing->classify($companyId, $branchId, $lines, $at, false, (string) $row->order_type);
            if ($classified['held'] !== []) {
                throw new TabletOrderException('tablet_lines_unavailable', 409, 'Some items are sold out or not available.',
                    ['lines' => array_map(static fn (array $line): array => [
                        'line_index' => (int) $line['line_index'], 'product_id' => (int) $line['product_id'],
                        'addon_id' => $line['addon_id'] === null ? null : (int) $line['addon_id'], 'reason' => (string) $line['reason'],
                    ], $classified['held'])]);
            }
            $loaded = $this->priced($companyId, $branchId, $lines, $at, (bool) $order->prices_include_tax, (string) $row->order_type);
            $price = Totals::priceOrder($loaded->pricingInput);
            $context = new QrSession(['company_id' => $companyId]);
            $before = $row->isDineIn() ? ($round?->priced_lines ?? []) : ($row->kitchen_lines ?? []);

            if ($row->isDineIn()) {
                $round = QrOrderRound::query()->whereKey($row->round_id)->lockForUpdate()->first();
                // Fix order 2 (F-11) — re-check under the lock: a round confirmed
                // or rejected since the read above is no longer editable.
                if ($round === null || $round->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION) {
                    throw new TabletOrderException('tablet_order_closed', 409, 'This tablet order is no longer open.');
                }
                $confirmPayload = $this->append->buildPayload($context, $loaded, $price, $now);
                $after = $this->freeze->handle($loaded, $price);
                $round->update(['priced_lines' => $after, 'confirm_payload' => $confirmPayload,
                    'subtotal_baisas' => $price->rawSubtotalBaisas, 'tax_baisas' => $price->taxTotalBaisas,
                    'total_baisas' => $price->grandTotalBaisas]);
                $ready = CookingTime::longest(array_map(static fn (array $item): mixed => $item['attributes']['cooking_minutes'] ?? null,
                    $confirmPayload['items']));
                $row->fill(['kitchen_lines' => null]);
            } else {
                $loyalty = TableLoyaltyDiscount::amount($order);
                if ($loyalty > 0 && $loyalty >= BillMoney::net($price->grandTotalBaisas, $price->taxTotalBaisas, $price->pricesIncludeTax)) {
                    throw new TabletOrderException('redeem_exceeds_order', 409, 'The approved points would leave nothing to pay; reject the points first.');
                }
                $this->purge($order);
                $itemIds = $this->append->handle($order, $context, $loaded, $price, $now);
                $after = $this->freeze->handle($loaded, $price);
                foreach ($after as $index => &$line) {
                    $line['order_item_id'] = $itemIds[$index];
                }
                unset($line);
                $header = $this->totals->header(['subtotal' => $price->rawSubtotalBaisas, 'tax' => $price->taxTotalBaisas,
                    'total' => $price->grandTotalBaisas, 'loyalty' => $loyalty, 'manual' => 0, 'comp' => 0,
                    'inclusive' => (int) $price->pricesIncludeTax]);
                unset($header['comp_total']);
                $order->update($header);
                $ready = CookingTime::readyInForOrder((int) $order->id);
                $row->fill(['kitchen_lines' => $after]);
            }
            $row->fill(['subtotal_baisas' => $price->rawSubtotalBaisas, 'tax_baisas' => $price->taxTotalBaisas,
                'total_baisas' => $price->grandTotalBaisas, 'ready_in_minutes' => $ready])->save();
            TabletOrderEvent::record($row, 'edited', $staffId, (int) $device->id, [
                'client_request_id' => $requestId, 'before' => $before, 'after' => $after,
            ]);

            return ['outcome' => 'edited', 'order' => $this->staff->present($row->fresh(), $device)];
        });
    }

    /** The held order's lines and their discounts; the points slot rows stay. */
    private function purge(Order $order): void
    {
        $itemIds = OrderItem::query()->where('order_id', $order->id)->pluck('id');
        if ($itemIds->isNotEmpty()) {
            OrderItemAddon::query()->whereIn('order_item_id', $itemIds)->delete();
        }
        OrderDiscount::query()->where('order_id', $order->id)->whereNotIn('amount_type_snapshot', TableLoyaltyDiscount::TYPES)->delete();
        OrderItem::query()->where('order_id', $order->id)->delete();
    }

    /** @param list<array<string, mixed>> $lines */
    private function priced(int $companyId, int $branchId, array $lines, DateTimeImmutable $at, bool $inclusive, string $type): QrPricingLoadResult
    {
        try {
            return $this->pricing->handle($companyId, $branchId, $lines, $at, $inclusive, $type);
        } catch (QrCatalogueException $exception) {
            throw new TabletOrderException('tablet_lines_unavailable', 409, 'Some items are sold out or not available.',
                ['lines' => [], 'reason' => $exception->codeName]);
        }
    }
}
