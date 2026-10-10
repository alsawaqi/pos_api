<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Kitchen\DomainCancellation;
use App\Kitchen\PreparationEvidence;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Support\Money;
use App\Support\Orders\ComboChildren;
use App\Support\Orders\OneLineNote;
use App\Support\Pricing\BillMoney;
use Carbon\CarbonInterface;
use RuntimeException;

/** Frozen-number reductions only; no catalogue reads or in-place audit edits. */
final class CancelStaffLineAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AppendTableSessionEventAction $journal,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
        private readonly PresentQrPendingOrderAction $present,
        private readonly BookTableCancellationWasteAction $waste,
        private readonly TableAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, ?string $uuid = null): array
    {
        return $this->resolver->locked($device, $payload, 'cancel_line', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid): array {
            $row = $uuid === null ? $seatings->firstWhere('client_request_id', $payload['seating_key']) : $seatings->firstWhere('uuid', $uuid);
            if ($row === null) {
                return $this->resolver->result('unknown_seating', $payload)
                    + ['cancelled_qty' => 0, 'unlinked_line_count' => 0, 'rounds' => []];
            }
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $primary = $resolved['primary'];
            if ($primary?->merged_into_id !== null) {
                $primary = $seatings->get((int) $primary->merged_into_id);
            }
            $order = $primary === null ? null : $orders->get((int) $primary->order_id);
            $result = fn (string $outcome): array => $this->resolver->result($outcome, $payload, $row, $primary, $order);
            $family = $seatings->filter(fn ($seat): bool => $primary !== null
                && ($seat->id === $primary->id || (int) $seat->merged_into_id === (int) $primary->id))->keys();
            $replay = TableSessionEvent::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->whereIn('table_session_id', $family)->where('event_type', 'round_resolved')
                ->where('payload->action', 'line_cancelled')
                ->where('payload->client_request_id', $payload['client_request_id'])->first();
            if ($replay !== null) {
                return $result('replayed') + array_intersect_key($replay->payload, array_flip([
                    'cancelled_qty', 'unlinked_line_count', 'grand_total_baisas', 'rounds', 'waste',
                ]));
            }
            if ($order === null || in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
                return $result('bill_terminal') + ['cancelled_qty' => 0, 'unlinked_line_count' => 0,
                    'grand_total_baisas' => $order === null ? 0 : Money::toBaisas($order->grand_total), 'rounds' => []];
            }
            $this->assertCancellable($order, $primary);
            // LAUNCH-P5 — table.cancel_line: a P5 build's block is checked
            // (refused 403 when not authorized); an old build keeps the
            // optional authorized_by text.
            $auth = $this->authorization->check($device, $payload, 'table.cancel_line', TableAuthorization::session($primary));
            $values = $this->cancelLocked($device, $primary, $order,
                $auth?->approvedBy() !== null ? $payload + ['approved_by_staff_id' => $auth->approvedBy()] : $payload);

            return $result($values['outcome']) + $values;
        });
    }

    public function assertCancellable(Order $order, TableSession $seat): void
    {
        if ($order->status !== Order::STATUS_OPEN || $seat->status !== TableSession::STATUS_OPEN
            || $seat->billing_at !== null || $this->present->charge($order, now()) !== 'none') {
            throw AdjustTableBillAction::refusal('bill_reserved', 'Reopen the bill and resolve its payment result before cancelling it.');
        }
    }

    /** Caller holds the table graph, order, credential and seating locks. */
    public function cancelLocked(Device $device, TableSession $primary, Order $order, array $payload): array
    {
        $this->baseline->handle($order);
        $cancelledItems = [];
        $rounds = QrOrderRound::query()->where('order_id', $order->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)->orderByDesc('round_no')->orderByDesc('id')
            ->lockForUpdate()->get();
        $this->refuseMealWithoutItsId($rounds, $payload);
        $remaining = (int) $payload['qty'];
        $unlinked = 0;
        $changes = [];
        foreach ($rounds as $round) {
            $lines = $round->priced_lines ?? [];
            $subtotal = (int) $round->subtotal_baisas;
            $tax = (int) $round->tax_baisas;
            $total = (int) $round->total_baisas;
            $lineDiscount = 0;
            foreach ($lines as $line) {
                if (! isset($line['held_reason'])) {
                    $lineDiscount += (int) ($line['line_discount_baisas'] ?? 0) - (int) ($line['cancelled_discount_baisas'] ?? 0);
                }
            }
            $discount = BillMoney::discount($subtotal, $tax, $total, (bool) $order->prices_include_tax);
            $orderDiscount = $discount - $lineDiscount;
            $deltaSubtotal = 0;
            $deltaDiscount = 0;
            $roundChanges = [];
            $indices = array_keys($lines);
            usort($indices, static fn ($a, $b): int => ($lines[$b]['line_index'] ?? $b) <=> ($lines[$a]['line_index'] ?? $a));
            foreach ($indices as $index) {
                $line = &$lines[$index];
                if (isset($line['held_reason']) || ! $this->matches($line, $payload)) {
                    unset($line);

                    continue;
                }
                $q = (int) $line['qty'];
                $c = (int) ($line['cancelled_qty'] ?? 0);
                if ($q - $c <= 0) {
                    unset($line);

                    continue;
                }
                if (! isset($line['order_item_id'])) {
                    $unlinked++;
                    unset($line);

                    continue;
                }
                if ($remaining === 0) {
                    unset($line);

                    continue;
                }
                $item = OrderItem::query()->whereKey($line['order_item_id'])->where('order_id', $order->id)->lockForUpdate()->first();
                if ($item === null) {
                    throw new RuntimeException('A linked cancellation line must belong to its bill.');
                }
                $k = min($q - $c, $remaining);
                $cancelledItems[] = [$item, $k];
                // LAUNCH-P4 — a combo line's chosen items are what was made:
                // their share of the cancelled quantity books the waste.
                foreach (OrderItem::query()->where('order_id', $order->id)->where('parent_order_item_id', $item->id)
                    ->where('status', '<>', OrderItem::STATUS_VOID)->orderBy('id')->get() as $child) {
                    $cancelledItems[] = [$child, round((float) $child->qty * $k / ($q - $c), 3)];
                }
                $unit = (int) $line['unit_price_baisas'];
                $originalDiscount = (int) ($line['line_discount_baisas'] ?? 0);
                $oldDiscount = intdiv($originalDiscount * ($q - $c), $q);
                $newDiscount = intdiv($originalDiscount * ($q - $c - $k), $q);
                $reduction = $oldDiscount - $newDiscount;
                $line['cancelled_qty'] = $c + $k;
                $line['cancelled_discount_baisas'] = (int) ($line['cancelled_discount_baisas'] ?? 0) + $reduction;
                $line['cancellations'][] = ['client_request_id' => $payload['client_request_id'],
                    'qty' => $k, 'discount_baisas' => $reduction, 'at' => $payload['cancelled_at']];
                $attributes = ['qty' => $q - $c - $k, 'line_total' => Money::toOmr($unit * ($q - $c - $k)),
                    'line_discount' => Money::toOmr($newDiscount)];
                if ($q === $c + $k) {
                    $attributes['status'] = OrderItem::STATUS_VOID;
                }
                $item->update($attributes);
                // LAUNCH-P4 — a combo line's chosen items follow its quantity.
                ComboChildren::follow($item, (float) ($q - $c), (float) ($q - $c - $k));
                $this->lineAudit($order, (int) $item->id, $reduction, $payload['client_request_id']);
                $deltaSubtotal += $unit * $k;
                $deltaDiscount += $reduction;
                $remaining -= $k;
                $roundChanges[] = ['round_id' => (int) $round->id, 'line_index' => (int) ($line['line_index'] ?? $index),
                    'qty' => $k, 'unit_price_baisas' => $unit, 'discount_baisas' => $reduction];
                unset($line);
            }
            if ($roundChanges === []) {
                continue;
            }
            $nextSubtotal = $subtotal - $deltaSubtotal;
            $nextLineDiscount = $lineDiscount - $deltaDiscount;
            $base = $subtotal - $lineDiscount;
            $nextBase = $nextSubtotal - $nextLineDiscount;
            $nextOrderDiscount = $base === 0 ? 0 : min($nextBase, intdiv($orderDiscount * $nextBase, $base));
            $taxedBase = $subtotal - $discount;
            $nextTaxedBase = $nextBase - $nextOrderDiscount;
            $nextTax = $taxedBase === 0 ? 0 : (int) round($tax * $nextTaxedBase / $taxedBase);
            $nextTotal = BillMoney::total($nextTaxedBase, $nextTax, (bool) $order->prices_include_tax);
            if (min($nextSubtotal, $nextTax, $nextTotal, $nextOrderDiscount) < 0) {
                throw new RuntimeException('Cancellation cannot produce negative frozen totals.');
            }
            $round->update(['priced_lines' => $lines, 'subtotal_baisas' => $nextSubtotal,
                'tax_baisas' => $nextTax, 'total_baisas' => $nextTotal]);
            if ($orderDiscount > $nextOrderDiscount) {
                $this->audit($order, ['order_item_id' => null, 'discount_id' => null, 'offer_id' => null,
                    'name_snapshot' => 'Line cancellation adjustment', 'amount_type_snapshot' => 'cancel_line'],
                    $orderDiscount - $nextOrderDiscount, $payload['client_request_id']);
            }
            foreach ($roundChanges as $change) {
                $changes[] = $change + ['subtotal_baisas' => $nextSubtotal, 'tax_baisas' => $nextTax, 'total_baisas' => $nextTotal];
            }
        }
        $cancelled = (int) $payload['qty'] - $remaining;
        if ($cancelled === 0) {
            return ['outcome' => 'nothing_to_cancel'] + ['cancelled_qty' => 0, 'unlinked_line_count' => $unlinked,
                'grand_total_baisas' => Money::toBaisas($order->grand_total), 'rounds' => []];
        }
        $this->totals->handle($order);
        $kitchenEvidence = PreparationEvidence::assertChoice($order, $cancelledItems, (bool) $payload['prepared']);
        $waste = $this->waste->plan($cancelledItems, (bool) $payload['prepared'], (string) $order->order_type);
        $afterPersist = $this->waste->book($order, $primary, $payload, $waste);
        $values = ['waste' => $waste, 'kitchen_preparation_evidence' => $kitchenEvidence, 'cancelled_qty' => $cancelled, 'unlinked_line_count' => $unlinked,
            'grand_total_baisas' => Money::toBaisas($order->grand_total), 'rounds' => $changes];
        $this->journal->handle($primary, 'round_resolved', $values + [
            'action' => 'line_cancelled', 'client_request_id' => $payload['client_request_id'],
            'product_id' => (int) $payload['product_id'], 'addon_ids' => $this->addonSet($payload['addon_ids'] ?? []),
            'notes_normalised' => $this->notes($payload['notes'] ?? null), 'qty' => (int) $payload['qty'],
            'prepared' => (bool) $payload['prepared'], 'reason' => $payload['reason'] ?? null,
            'authorized_by' => $payload['authorized_by'] ?? null, 'order_uuid' => $order->uuid,
            'staff_id' => $payload['staff_id'] ?? null, 'whole_bill' => $payload['whole_bill'] ?? false,
        ] + (isset($payload['meal_id']) ? ['meal_id' => (int) $payload['meal_id']] : [])
            + (isset($payload['combo']) && is_array($payload['combo']) ? ['combo_picks' => $this->requestPicks($payload['combo'], [])] : [])
            + (isset($payload['approved_by_staff_id']) ? ['approved_by_staff_id' => (int) $payload['approved_by_staff_id']] : []),
            (int) $device->id, afterPersist: $afterPersist);

        DomainCancellation::reconcile($order, $payload['client_request_id']);

        return ['outcome' => 'cancelled'] + $values;
    }

    /**
     * Combo fix order 2 (C-21) — the line a cancel means: product + meal +
     * add-ons + notes, and, when the request sends them, the combo / meal
     * picks. A plain main and a meal main never match each other. The whole
     * bill (cancel_bill) takes every line of the product, meal or not.
     */
    private function matches(array $line, array $payload): bool
    {
        if (! $this->sameItem($line, $payload)) {
            return false;
        }
        if (($payload['whole_bill'] ?? false) === true) {
            return true;
        }
        $lineMeal = isset($line['meal_id']) ? (int) $line['meal_id'] : null;
        $askedMeal = isset($payload['meal_id']) ? (int) $payload['meal_id'] : null;
        if ($lineMeal !== $askedMeal) {
            return false;
        }
        if (isset($payload['combo']) && is_array($payload['combo'])) {
            $components = is_array($line['components'] ?? null) ? $line['components'] : [];

            return $this->requestPicks($payload['combo'], $components) === $this->linePicks($components);
        }

        return true;
    }

    private function sameItem(array $line, array $payload): bool
    {
        return (int) $line['product_id'] === (int) $payload['product_id']
            && $this->addonSet(array_column($line['addons'] ?? [], 'add_on_id')) === $this->addonSet($payload['addon_ids'] ?? [])
            && $this->notes($line['notes'] ?? null) === $this->notes($payload['notes'] ?? null);
    }

    /**
     * Fix order 2 (C-21) — an old build (no meal_id) asking for a product
     * whose only lines left are meals: refused, never a meal cancelled by a
     * request that did not name it.
     *
     * @param  iterable<QrOrderRound>  $rounds
     */
    private function refuseMealWithoutItsId(iterable $rounds, array $payload): void
    {
        if (isset($payload['meal_id']) || ($payload['whole_bill'] ?? false) === true) {
            return;
        }
        $plain = false;
        $meal = null;
        foreach ($rounds as $round) {
            foreach ($round->priced_lines ?? [] as $line) {
                if (isset($line['held_reason']) || (int) $line['qty'] - (int) ($line['cancelled_qty'] ?? 0) <= 0 || ! $this->sameItem($line, $payload)) {
                    continue;
                }
                if (isset($line['meal_id'])) {
                    $meal ??= $line;
                } elseif ($this->matches($line, $payload)) {
                    $plain = true;
                }
            }
        }
        if (! $plain && $meal !== null) {
            $name = (string) ($meal['display_name'] ?? $meal['product_name'] ?? 'This item');
            throw new QrDineInException('meal_id_required', 409,
                sprintf('"%s" is a meal. Cancel it from an updated app, which sends the meal with the request; nothing was cancelled.', $name),
                details: ['meal_id' => (int) $meal['meal_id'], 'product_id' => (int) $meal['product_id']]);
        }
    }

    /**
     * A frozen combo / meal line's picks per ONE: its choices and upgrades
     * (the fixed items are the same on every such line), as sorted
     * [line_id, product_id, qty, add-ons, notes].
     *
     * @param  list<array<string, mixed>>  $components
     * @return list<string>
     */
    private function linePicks(array $components): array
    {
        $picks = [];
        foreach ($components as $component) {
            if (($component['kind'] ?? null) === 'fixed') {
                continue;
            }
            $picks[] = $this->pick((int) $component['line_id'], (int) $component['product_id'], $component['qty'] ?? 1,
                array_column($component['addons'] ?? [], 'add_on_id'), $component['notes'] ?? null);
        }
        sort($picks);

        return $picks;
    }

    /**
     * The request's picks in the same form; an item the frozen line has as a
     * plain fixed item (sent or not by the device) is left out.
     *
     * @param  list<mixed>  $combo
     * @param  list<array<string, mixed>>  $components
     * @return list<string>
     */
    private function requestPicks(array $combo, array $components): array
    {
        $fixed = [];
        foreach ($components as $component) {
            if (($component['kind'] ?? null) === 'fixed') {
                $fixed[(int) $component['line_id'].':'.(int) $component['product_id']] = true;
            }
        }
        $picks = [];
        foreach ($combo as $entry) {
            $entry = (array) $entry;
            if (isset($fixed[(int) ($entry['line_id'] ?? 0).':'.(int) ($entry['product_id'] ?? 0)])) {
                continue;
            }
            $addons = $entry['addon_ids'] ?? array_column((array) ($entry['addons'] ?? []), 'add_on_id');
            $picks[] = $this->pick((int) ($entry['line_id'] ?? 0), (int) ($entry['product_id'] ?? 0), $entry['qty'] ?? 1,
                (array) $addons, $entry['notes'] ?? null);
        }
        sort($picks);

        return $picks;
    }

    private function pick(int $lineId, int $productId, mixed $qty, array $addons, ?string $notes): string
    {
        return json_encode([$lineId, $productId, round((float) $qty, 3), $this->addonSet($addons), $this->notes($notes)], JSON_THROW_ON_ERROR);
    }

    public function addonSet(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    public function notes(?string $notes): string
    {
        // Fix order A-1 (H1) — the stored note is one line, cut to 140: a
        // selector sent with the raw note still matches its line.
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) OneLineNote::cut($notes ?? ''))));
    }

    private function lineAudit(Order $order, int $itemId, int $reduction, string $requestId): void
    {
        foreach (OrderDiscount::query()->where('order_id', $order->id)->where('order_item_id', $itemId)
            ->where('amount', '>', 0)->orderBy('id')->get() as $row) {
            if ($reduction === 0) {
                break;
            }
            $amount = min($reduction, Money::toBaisas($row->amount));
            $this->audit($order, $row->only(['discount_id', 'offer_id', 'order_item_id', 'name_snapshot', 'amount_type_snapshot']), $amount, $requestId);
            $reduction -= $amount;
        }
        if ($reduction !== 0) {
            throw new RuntimeException('Frozen line discount is missing its audit attribution.');
        }
    }

    private function audit(Order $order, array $attributes, int $amount, string $requestId): void
    {
        OrderDiscount::query()->create($attributes + ['company_id' => $order->company_id,
            'branch_id' => $order->branch_id, 'order_id' => $order->id, 'amount' => Money::toOmr(-$amount),
            'reason' => 'cancel:'.$requestId, 'applied_at' => now()]);
    }
}
