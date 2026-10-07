<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Pricing\Totals;
use Illuminate\Support\Facades\DB;

/** Staff-only, append-only additions to the customer's existing quick bill. */
final class AppendQuickQrOrderItemsAction
{
    public function __construct(
        private readonly PresentQrPendingOrderAction $present,
        private readonly LoadQrPricingInputAction $pricing,
        private readonly AppendQrPricedLinesAction $append,
        private readonly FreezeQrRoundLinesAction $freeze,
    ) {}

    /** @param array{client_request_id: string, lines: list<array<string, mixed>>} $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, string $uuid, array $payload, bool $workspace = false): array
    {
        $this->present->assertAttended($device);

        return DB::transaction(function () use ($device, $uuid, $payload, $workspace): array {
            // Serialize with revocation and payment on device -> order.
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if ($lockedDevice === null) {
                throw new QrChargeException('device_not_attended', 409, 'The attended device is no longer active.');
            }
            $this->present->assertAttended($lockedDevice);
            $order = Order::query()->where('uuid', $uuid)
                ->where('company_id', $lockedDevice->company_id)
                ->where('branch_id', $lockedDevice->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)->where('order_type', 'quick')
                ->whereNull('table_id')->whereNull('table_session_id')
                ->lockForUpdate()->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The quick order was not found.');
            }

            // The locked bill is the serialization boundary, including for a
            // retained bill whose original phone credential has been pruned.
            $existing = QrOrderRound::query()->where('order_id', $order->id)
                ->whereNull('qr_session_id')->whereNull('table_session_id')
                ->where('client_request_id', $payload['client_request_id'])->first();
            if ($existing !== null) {
                $original = array_map(static fn (array $line): array => [
                    'product_id' => $line['product_id'], 'qty' => $line['qty'],
                    'addon_ids' => array_column($line['addons'], 'add_on_id'),
                    'notes' => $line['notes'],
                    'meal_id' => $line['meal_id'] ?? null,
                    'combo' => array_map(static fn (array $component): array => [
                        'line_id' => $component['line_id'] ?? null, 'product_id' => $component['product_id'], 'qty' => $component['qty'],
                        'addon_ids' => array_column($component['addons'], 'add_on_id'), 'notes' => $component['notes'],
                    ], $line['components'] ?? []),
                ], $existing->priced_lines);
                if ($this->requestLines($original) !== $this->requestLines($payload['lines'])) {
                    throw new QrChargeException('idempotency_conflict', 409, 'This request already added different items.');
                }

                // Read-only acknowledgement after a lost response, even if
                // payment has since started or finished. It grants no edit.
                return $this->result($order, $existing, true, $workspace);
            }

            if ($order->transferred_to_device_id !== null) {
                throw new QrChargeException('order_not_editable', 409, 'The order has been transferred.');
            }
            $charge = $this->present->charge($order, now());
            if ($charge !== 'none') {
                throw new QrChargeException(
                    $charge === 'live_claim' ? 'charge_already_claimed' : 'qr_charge_recovery_required',
                    409,
                    'Resolve the existing payment attempt before adding items.',
                );
            }
            if ($order->status !== Order::STATUS_HELD) {
                throw new QrChargeException('order_not_editable', 409, 'Only an unpaid quick order sent to the counter can be changed.');
            }

            // LAUNCH-P4 — an addition is priced in the bill's own tax mode.
            $loaded = $this->pricing->handleForStaff(
                (int) $order->company_id, (int) $order->branch_id, $payload['lines'],
                null, (bool) $order->prices_include_tax, (string) $order->order_type,
            );
            $price = Totals::priceOrder($loaded->pricingInput);
            // LAUNCH-P2 P2-7 — sell, but warn: the shelf count never refuses staff additions.
            $at = now();
            // Typed pricing context only: staff never create/revive a phone
            // session or rewrite the customer's identity, reference or items.
            $context = new QrSession(['company_id' => $order->company_id]);
            $itemIds = $this->append->handle($order, $context, $loaded, $price, $at);
            $lines = $this->freeze->handle($loaded, $price);
            foreach ($lines as $index => &$line) {
                $line['order_item_id'] = $itemIds[$index];
            }
            unset($line);
            $round = QrOrderRound::query()->create([
                'order_id' => $order->id, 'qr_session_id' => null, 'table_session_id' => null,
                'client_request_id' => $payload['client_request_id'],
                'round_no' => (int) QrOrderRound::query()->where('order_id', $order->id)->max('round_no') + 1,
                'status' => QrOrderRound::STATUS_ACCEPTED, 'needs_review' => false,
                'priced_lines' => $lines, 'confirm_payload' => null,
                'subtotal_baisas' => $price->rawSubtotalBaisas,
                'tax_baisas' => $price->taxTotalBaisas, 'total_baisas' => $price->grandTotalBaisas,
                'submitted_at' => $at, 'resolved_at' => $at,
                'resolved_by_device_id' => $lockedDevice->id,
                // Quick orders must not enter the dine-in auto-print feed.
                'accepted_seq' => null,
            ]);
            // Freeze the new batch only, just like staff table additions.
            // Never re-price old items or recalculate their historic discounts.
            $order->update([
                'subtotal' => Money::toOmr(Money::toBaisas($order->subtotal) + $price->rawSubtotalBaisas),
                'discount_total' => Money::toOmr(Money::toBaisas($order->discount_total) + $price->discountTotalBaisas),
                'tax_total' => Money::toOmr(Money::toBaisas($order->tax_total) + $price->taxTotalBaisas),
                'grand_total' => Money::toOmr(Money::toBaisas($order->grand_total) + $price->grandTotalBaisas),
            ]);

            return $this->result($order, $round, false, $workspace);
        }, 5);
    }

    /** @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function requestLines(array $lines): array
    {
        return array_map(static function (array $line): array {
            $addons = array_map(intval(...), $line['addon_ids']);
            sort($addons);

            return [
                'product_id' => (int) $line['product_id'], 'qty' => (int) $line['qty'],
                'addon_ids' => $addons, 'notes' => $line['notes'] ?? '',
                // LAUNCH combo add-on — the meal and the combo / meal items are part of the request.
                'meal_id' => isset($line['meal_id']) ? (int) $line['meal_id'] : null,
                'combo' => array_map(static function (array $component): array {
                    $ids = array_map(intval(...), $component['addon_ids'] ?? array_column($component['addons'] ?? [], 'add_on_id'));
                    sort($ids);

                    return ['line_id' => (int) ($component['line_id'] ?? 0), 'product_id' => (int) $component['product_id'],
                        'qty' => (int) ($component['qty'] ?? 1), 'addon_ids' => $ids, 'notes' => $component['notes'] ?? ''];
                }, is_array($line['combo'] ?? null) ? $line['combo'] : []),
            ];
        }, $lines);
    }

    /** @return array<string, mixed> */
    private function result(Order $order, QrOrderRound $round, bool $replayed, bool $workspace): array
    {
        $order->load(['items' => fn ($q) => $q->orderBy('id'), 'items.addons', 'comps' => fn ($q) => $q->orderBy('id')]);
        $session = $order->qr_session_id === null ? null : QrSession::query()
            ->whereKey($order->qr_session_id)->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->first();
        $phone = $order->customer_id === null ? null : Customer::withTrashed()
            ->whereKey($order->customer_id)->where('company_id', $order->company_id)->value('phone');

        return [
            'order' => $this->present->handle($order, $session, $phone, now(), $workspace),
            'addition' => [
                'id' => (int) $round->id, 'round_no' => (int) $round->round_no,
                'subtotal_baisas' => (int) $round->subtotal_baisas,
                'tax_baisas' => (int) $round->tax_baisas, 'total_baisas' => (int) $round->total_baisas,
                'priced_lines' => $round->priced_lines,
            ],
            'replayed' => $replayed,
        ];
    }
}
