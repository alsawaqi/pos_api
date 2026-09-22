<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrOrderRound;
use App\Models\TableSessionEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

final class CancelTableBillAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly CancelStaffLineAction $lines,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AppendTableSessionEventAction $journal,
        private readonly CloseTableSessionForOrderAction $close,
    ) {}

    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, ?string $uuid = null): array
    {
        return $this->resolver->locked($device, $payload, 'cancel_bill', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $seat = $resolved['primary'];
            $order = $seat === null ? null : $orders->get((int) $seat->order_id);
            if ($seat === null || $order === null || (int) $order->table_session_id !== (int) $seat->id) {
                throw AdjustTableBillAction::refusal('nothing_to_cancel', 'This table has no accepted bill to cancel.');
            }
            $hash = hash('sha256', json_encode(Arr::sortRecursive(Arr::except($payload, ['client_timestamp'])), JSON_THROW_ON_ERROR));
            $prior = TableSessionEvent::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->where('table_session_id', $seat->id)->where('event_type', 'bill_cancelled')
                ->where('payload->client_request_id', $payload['client_request_id'])->first();
            $result = fn (string $outcome): array => $this->resolver->result($outcome, $payload, $resolved['row'], $seat, $order);
            if ($prior !== null) {
                if (($prior->payload['request_hash'] ?? null) !== $hash) {
                    throw AdjustTableBillAction::refusal('cancel_request_conflict', 'This cancellation reference was already used for another request.');
                }

                return $result('replayed') + $prior->payload['result'];
            }
            if (Payment::query()->where('order_id', $order->id)->where('status', Payment::STATUS_SUCCESS)->exists()) {
                throw AdjustTableBillAction::refusal('bill_has_payment', 'This bill has a payment and cannot be cancelled at the table.');
            }
            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
                throw AdjustTableBillAction::refusal('bill_terminal', 'This bill is already paid or cancelled.');
            }
            $this->lines->assertCancellable($order, $seat);
            $groups = $this->groups($order);
            if ($groups === []) {
                throw AdjustTableBillAction::refusal('nothing_to_cancel', 'This bill has no accepted items. Use Clear Table for an empty table.');
            }
            $asked = [];
            foreach ($payload['lines'] as $line) {
                $key = $this->key($line);
                if (isset($asked[$key])) {
                    throw new QrDineInException('bill_changed', 409, 'The bill changed. Review all remaining items and try again.', details: ['groups' => array_values($groups)]);
                }
                $asked[$key] = (int) $line['qty'];
            }
            $expected = array_map(static fn ($g): int => $g['qty'], $groups);
            ksort($expected);
            ksort($asked);
            if ($asked !== $expected) {
                throw new QrDineInException('bill_changed', 409, 'The bill changed. Review all remaining items and try again.', details: ['groups' => array_values($groups)]);
            }
            $ids = array_column($payload['lines'], 'client_request_id');
            if (in_array($payload['client_request_id'], $ids, true) || TableSessionEvent::query()
                ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->whereIn('payload->client_request_id', $ids)->exists()) {
                throw AdjustTableBillAction::refusal('cancel_request_conflict', 'Use new references for these line cancellations.');
            }
            // Reverse the manual slots before line arithmetic can clamp them.
            $amounts = $this->totals->amounts($order);
            $this->totals->reverseDiscount($order, $amounts['manual']);
            TableLoyaltyDiscount::clear($order);
            $this->totals->reverseComp($order, $amounts['comp']);
            $this->totals->handle($order);
            $results = [];
            foreach ($payload['lines'] as $line) {
                $values = $this->lines->cancelLocked($device, $seat, $order, array_replace(
                    Arr::except($payload, ['lines']), $line, ['whole_bill' => true],
                ));
                if ($values['cancelled_qty'] !== (int) $line['qty'] || $values['unlinked_line_count'] !== 0) {
                    throw AdjustTableBillAction::refusal('bill_changed', 'Some bill lines could not be linked. Review the bill before cancelling it.');
                }
                $results[] = ['client_request_id' => $line['client_request_id']] + $values;
            }
            $order->update(['status' => Order::STATUS_VOID, 'void_reason_label' => 'Cancelled at table', 'closed_at' => now()]);
            $this->close->handle($order, now(), 'cancelled', (int) $device->id);
            $ack = ['client_request_id' => $payload['client_request_id'], 'lines' => $results,
                'status' => 'void', 'grand_total_baisas' => 0];
            $this->journal->handle($seat, 'bill_cancelled', [
                'action' => 'bill_cancelled', 'client_request_id' => $payload['client_request_id'],
                'line_request_ids' => $ids, 'reason' => $payload['reason'], 'authorized_by' => $payload['authorized_by'],
                'staff_id' => $payload['staff_id'] ?? null, 'request_hash' => $hash, 'result' => $ack,
            ], (int) $device->id);

            return $result('cancelled') + $ack;
        });
    }

    private function key(array $line): string
    {
        return json_encode([(int) $line['product_id'], $this->lines->addonSet($line['addon_ids'] ?? []),
            $this->lines->notes($line['notes'] ?? null)], JSON_THROW_ON_ERROR);
    }

    private function groups(Order $order): array
    {
        $groups = [];
        foreach (QrOrderRound::query()->where('order_id', $order->id)->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->orderBy('id')->lockForUpdate()->get() as $round) {
            foreach ($round->priced_lines ?? [] as $line) {
                $remaining = (int) ($line['qty'] ?? 0) - (int) ($line['cancelled_qty'] ?? 0);
                if (isset($line['held_reason']) || $remaining <= 0) {
                    continue;
                }
                $group = ['product_id' => (int) $line['product_id'],
                    'addon_ids' => $this->lines->addonSet(array_column($line['addons'] ?? [], 'add_on_id')),
                    'notes' => $this->lines->notes($line['notes'] ?? null), 'qty' => 0];
                $key = $this->key($group);
                $groups[$key] ??= $group;
                $groups[$key]['qty'] += $remaining;
            }
        }

        return $groups;
    }
}
