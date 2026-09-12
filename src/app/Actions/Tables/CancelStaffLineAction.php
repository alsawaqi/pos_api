<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\TableSessionEvent;
use App\Support\Money;
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
                    'cancelled_qty', 'unlinked_line_count', 'grand_total_baisas', 'rounds',
                ]));
            }
            if ($order === null || $order->status !== Order::STATUS_OPEN) {
                return $result('bill_terminal') + ['cancelled_qty' => 0, 'unlinked_line_count' => 0,
                    'grand_total_baisas' => $order === null ? 0 : Money::toBaisas($order->grand_total), 'rounds' => []];
            }
            $this->baseline->handle($order);
            $rounds = QrOrderRound::query()->where('order_id', $order->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)->orderByDesc('round_no')->orderByDesc('id')
                ->lockForUpdate()->get();
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
                $discount = $subtotal + $tax - $total;
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
                $nextTotal = $nextTaxedBase + $nextTax;
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
                return $result('nothing_to_cancel') + ['cancelled_qty' => 0, 'unlinked_line_count' => $unlinked,
                    'grand_total_baisas' => Money::toBaisas($order->grand_total), 'rounds' => []];
            }
            $this->totals->handle($order);
            $values = ['cancelled_qty' => $cancelled, 'unlinked_line_count' => $unlinked,
                'grand_total_baisas' => Money::toBaisas($order->grand_total), 'rounds' => $changes];
            $this->journal->handle($primary, 'round_resolved', $values + [
                'action' => 'line_cancelled', 'client_request_id' => $payload['client_request_id'],
                'product_id' => (int) $payload['product_id'], 'addon_ids' => $this->addonSet($payload['addon_ids'] ?? []),
                'notes_normalised' => $this->notes($payload['notes'] ?? null), 'qty' => (int) $payload['qty'],
                'prepared' => (bool) $payload['prepared'], 'reason' => $payload['reason'] ?? null,
                'authorized_by' => $payload['authorized_by'] ?? null, 'order_uuid' => $order->uuid,
            ], (int) $device->id);

            return $result('cancelled') + $values;
        });
    }

    private function matches(array $line, array $payload): bool
    {
        return (int) $line['product_id'] === (int) $payload['product_id']
            && $this->addonSet(array_column($line['addons'] ?? [], 'add_on_id')) === $this->addonSet($payload['addon_ids'] ?? [])
            && $this->notes($line['notes'] ?? null) === $this->notes($payload['notes'] ?? null);
    }

    private function addonSet(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    private function notes(?string $notes): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $notes ?? '')));
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
