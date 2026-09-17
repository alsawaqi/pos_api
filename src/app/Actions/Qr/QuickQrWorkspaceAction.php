<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Online, revision-checked edits and explicit handoffs of the existing bill. */
final class QuickQrWorkspaceAction
{
    public function __construct(private readonly PresentQrPendingOrderAction $present, private readonly AppendQuickQrOrderItemsAction $append) {}

    public static function revision(Order $order): string
    {
        $order->loadMissing(['items.addons', 'comps']);

        return hash('sha256', json_encode([
            $order->status, $order->transferred_to_device_id, $order->subtotal, $order->discount_total,
            $order->tax_total, $order->grand_total, $order->items->sortBy('id')->values()->toArray(),
            $order->comps->sortBy('id')->values()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function handle(Device $device, string $uuid, array $payload): array
    {
        $this->present->assertAttended($device);

        return DB::transaction(function () use ($device, $uuid, $payload): array {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->present->assertAttended($device);
            $order = Order::query()->where('uuid', $uuid)->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'quick')->whereNull('table_id')->lockForUpdate()->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The quick order was not found.');
            }
            $input = ['order_uuid' => $uuid] + $payload;
            $saved = SyncEvent::query()->where('device_id', $device->id)->where('client_event_id', $payload['client_request_id'])->first();
            if ($saved !== null) {
                if ($saved->event_type !== 'qr.quick.workspace' || $saved->payload_json != $input) {
                    throw new QrChargeException('idempotency_conflict', 409, 'This request was already used for another operation.');
                }

                return array_replace($saved->result_json, ['replayed' => true]);
            }
            if ($order->status !== Order::STATUS_HELD || ! $this->present->hasNoChargeProvenance($order)
                || $order->transferred_to_device_id !== null || $order->payments()->exists()) {
                throw new QrChargeException('order_not_editable', 409, 'The order is reserved, transferred or requires payment recovery.');
            }
            $order->load(['items.addons', 'comps']);
            if (! hash_equals(self::revision($order), $payload['revision'])) {
                throw new QrChargeException('order_changed', 409, 'The bill changed. Refresh before editing it.');
            }
            $before = $this->present->mapOrder($order);
            if ($payload['operation'] === 'transfer') {
                $target = Device::query()->whereKey($payload['target_device_id'])->where('company_id', $device->company_id)
                    ->where('branch_id', $device->branch_id)->where('status', 'active')->whereKeyNot($device->id)->first();
                if ($target === null || ! $target->isAssigned()
                    || ! ($target->isPaymentStation() || in_array($target->device_type, ['fixed_pos', 'handheld'], true))
                    || Money::toBaisas($order->grand_total) <= 0) {
                    throw new QrChargeException('transfer_unavailable', 409, 'Choose another active payment device in this branch.');
                }
                $order->update([
                    'status' => $target->isPaymentStation() ? Order::STATUS_AWAITING_PAYMENT : Order::STATUS_HELD,
                    'transferred_to_device_id' => $target->id, 'transferred_from_device_id' => $device->id,
                    'transferred_at' => now(),
                ]);
            } else {
                if ($order->comps->isNotEmpty()) {
                    throw new QrChargeException('order_not_editable', 409, 'A manager must resolve the existing comp before changing these items.');
                }
                $ids = $payload['item_ids'] ?? [$payload['item_id'] ?? 0];
                $items = $payload['operation'] === 'clear' ? $order->items : $order->items->whereIn('id', $ids)->sortBy('id');
                if ($payload['operation'] !== 'clear' && (count($ids) !== $items->count()
                    || ! in_array($payload['item_id'], $ids, true))) {
                    throw new QrChargeException('order_changed', 409, 'The selected items no longer match this bill.');
                }
                if ($items->isEmpty()) {
                    throw new QrChargeException('order_changed', 409, 'This item no longer exists on the bill.');
                }
                $remainingQuantity = $payload['operation'] === 'quantity' ? (int) $payload['qty'] : 0;
                if ($remainingQuantity > $items->where('status', '!=', 'void')->sum('qty')) {
                    throw new QrChargeException('validation_failed', 422, 'Add extra quantities through the priced addition endpoint.');
                }
                foreach ($items as $item) {
                    if ((float) $item->qty <= 0 || $item->status === 'void') {
                        continue;
                    }
                    $remaining = min($remainingQuantity, (int) $item->qty);
                    $remainingQuantity -= $remaining;
                    if ($remaining > (float) $item->qty) {
                        throw new QrChargeException('validation_failed', 422, 'Add extra quantities through the priced addition endpoint.');
                    }
                    $this->reduce($order, $item, $remaining, $payload['client_request_id']);
                }
                if ($payload['operation'] === 'replace') {
                    $this->append->handle($device, $uuid, [
                        'client_request_id' => $payload['client_request_id'], 'lines' => $payload['lines'],
                    ]);
                }
            }
            $order->refresh()->load(['items.addons', 'comps']);
            $result = ['order' => $this->present->handle($order, $order->qr_session_id === null ? null : QrSession::find($order->qr_session_id), $order->customer_id === null ? null : Customer::withTrashed()->whereKey($order->customer_id)->where('company_id', $device->company_id)->value('phone'), now(), true), 'replayed' => false];
            SyncEvent::query()->create([
                'client_event_id' => $payload['client_request_id'], 'device_id' => $device->id,
                'event_type' => 'qr.quick.workspace', 'payload_json' => $input,
                'client_timestamp' => now(), 'server_received_at' => now(), 'processed_at' => now(),
                'ack_status' => SyncEvent::STATUS_PROCESSED, 'result_json' => $result + ['before' => $before],
            ]);

            return $result;
        }, 5);
    }

    /** Reduce frozen amounts; neither current catalogue prices nor stock are touched. */
    private function reduce(Order $order, $item, int $remaining, string $requestId): void
    {
        $rounds = QrOrderRound::query()->where('order_id', $order->id)->where('status', QrOrderRound::STATUS_ACCEPTED)->lockForUpdate()->get();
        $round = $rounds->first(fn ($r): bool => collect($r->priced_lines)->contains(fn ($line): bool => (int) ($line['order_item_id'] ?? 0) === (int) $item->id));
        // Original quick checkout predates staff rounds. Its remaining frozen
        // financial batch is the order header less the accepted staff batches.
        $subtotal = $round?->subtotal_baisas ?? Money::toBaisas($order->subtotal) - (int) $rounds->sum('subtotal_baisas');
        $tax = $round?->tax_baisas ?? Money::toBaisas($order->tax_total) - (int) $rounds->sum('tax_baisas');
        $total = $round?->total_baisas ?? Money::toBaisas($order->grand_total) - (int) $rounds->sum('total_baisas');
        $discount = $subtotal + $tax - $total;
        $oldQty = (float) $item->qty;
        $oldRaw = Money::toBaisas($item->line_total);
        $nextRaw = (int) round($oldRaw * $remaining / $oldQty);
        $removed = $oldRaw - $nextRaw;
        $nextSubtotal = $subtotal - $removed;
        $linkedIds = $rounds->flatMap(fn ($r) => collect($r->priced_lines)->pluck('order_item_id'))->filter()->all();
        $batchItems = OrderItem::query()->where('order_id', $order->id);
        if ($round === null) {
            $batchItems->whereNotIn('id', $linkedIds);
        } else {
            $batchItems->whereIn('id', array_column($round->priced_lines, 'order_item_id'));
        }
        $lineDiscounts = Money::toBaisas($batchItems->sum('line_discount'));
        $oldLineDiscount = Money::toBaisas($item->line_discount);
        $nextLineDiscount = (int) round($oldLineDiscount * $remaining / $oldQty);
        $nextLineDiscounts = $lineDiscounts - $oldLineDiscount + $nextLineDiscount;
        $orderDiscount = $discount - $lineDiscounts;
        $orderBase = $subtotal - $lineDiscounts;
        $nextOrderBase = $nextSubtotal - $nextLineDiscounts;
        $nextOrderDiscount = $orderBase === 0 ? 0 : intdiv($orderDiscount * $nextOrderBase, $orderBase);
        $nextDiscount = $nextLineDiscounts + $nextOrderDiscount;
        $base = $subtotal - $discount;
        $nextBase = $nextSubtotal - $nextDiscount;
        $nextTax = $base === 0 ? 0 : (int) round($tax * $nextBase / $base);
        $nextTotal = $nextBase + $nextTax;
        if (min($nextSubtotal, $nextDiscount, $nextTax, $nextTotal) < 0) {
            throw new QrChargeException('order_not_editable', 409, 'The frozen bill amounts need review.');
        }
        $oldLineDiscount = Money::toBaisas($item->line_discount);
        $nextLineDiscount = (int) round($oldLineDiscount * $remaining / $oldQty);
        $item->update(['qty' => $remaining, 'line_total' => Money::toOmr($nextRaw),
            'line_discount' => Money::toOmr($nextLineDiscount), 'status' => $remaining === 0 ? 'void' : $item->status]);
        if ($discount !== $nextDiscount) {
            OrderDiscount::query()->create(['order_id' => $order->id, 'source' => 'order',
                'name_snapshot' => 'Quick order edit '.$requestId, 'amount_type_snapshot' => 'cancel_line',
                'amount' => Money::toOmr($nextDiscount - $discount), 'applied_at' => now()]);
        }
        if ($round !== null) {
            $lines = $round->priced_lines;
            foreach ($lines as &$line) {
                if ((int) ($line['order_item_id'] ?? 0) === (int) $item->id) {
                    $line['cancelled_qty'] = (int) ($line['cancelled_qty'] ?? 0) + (int) $oldQty - $remaining;
                    $line['cancellations'][] = ['client_request_id' => $requestId, 'qty' => (int) $oldQty - $remaining];
                }
            }
            unset($line);
            $round->update(['priced_lines' => $lines, 'subtotal_baisas' => $nextSubtotal, 'tax_baisas' => $nextTax, 'total_baisas' => $nextTotal]);
        }
        $order->update([
            'subtotal' => Money::toOmr(Money::toBaisas($order->subtotal) - $removed),
            'discount_total' => Money::toOmr(Money::toBaisas($order->discount_total) - $discount + $nextDiscount),
            'tax_total' => Money::toOmr(Money::toBaisas($order->tax_total) - $tax + $nextTax),
            'grand_total' => Money::toOmr(Money::toBaisas($order->grand_total) - $total + $nextTotal),
        ]);
    }
}
