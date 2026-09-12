<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Evidence for a later client review, NEVER permission to delete or resend. */
final class ReadTableDraftProofAction
{
    public function __construct(
        private readonly ReadTableDetailAction $detail,
        private readonly FrozenLegacyTableBill $frozen,
    ) {}

    public function handle(Device $device, int $tableId, array $input): array
    {
        return $this->detail->inspect($device, $tableId, function (Device $current, array $detail) use ($input, $tableId): array {
            $values = Validator::make($input, [
                'order_uuid' => ['required', 'uuid'],
                'kind' => ['required', 'in:staff_rounds,legacy_hold'],
                'event_ids' => ['required_if:kind,staff_rounds', 'prohibited_if:kind,legacy_hold', 'array', 'min:1', 'max:100'],
                'event_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            ])->validate();
            if (($detail['bill']['uuid'] ?? null) !== $values['order_uuid']) {
                throw $this->refusal('draft_proof_bill_changed', 'The local draft does not identify this table bill. Keep the draft.');
            }
            $order = Order::query()->where('company_id', $current->company_id)->where('branch_id', $current->branch_id)
                ->where('uuid', $values['order_uuid'])->firstOrFail();
            // Keep owner admission; the only exception is the existing QR
            // adoption's exact station/credential/bill/seating chain. Each ACK
            // must STILL belong to $current in event(), never to the station.
            if ((int) $order->device_id !== (int) $current->id && ! $this->stationAdopted($order, $current)) {
                throw $this->refusal('draft_proof_owner_required', 'Review this draft on the device that owns its bill.');
            }
            $seat = TableSession::query()->where('company_id', $current->company_id)->where('branch_id', $current->branch_id)
                ->whereKey($order->table_session_id)->where('order_id', $order->id)->where('table_id', $tableId)->first();
            if ($seat === null || $detail['table']['archived'] || $detail['table']['status'] !== 'active'
                || $seat->status !== TableSession::STATUS_OPEN || $seat->merged_into_id !== null || $seat->billing_at !== null
                || $detail['seating']['uuid'] !== $seat->uuid || $detail['seating']['joined_table_ids'] !== []
                || $order->status !== Order::STATUS_OPEN || $order->closed_at !== null
                || ! in_array($order->source, ['main_pos', 'handheld', Order::SOURCE_QR_WEB], true)
                || DB::table('pos_order_tables')->where('order_id', $order->id)->exists()) {
                throw $this->ineligible();
            }
            foreach (['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas', 'charge_claimed_at',
                'charge_deadline_at', 'charge_outcome', 'transferred_to_device_id', 'transferred_from_device_id',
                'transferred_at', 'delivery_provider_id'] as $field) {
                if ($order->$field !== null) {
                    throw $this->ineligible();
                }
            }
            if (DB::table('pos_payments')->where('order_id', $order->id)->exists()
                || collect($detail['rounds'])->contains('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)) {
                throw $this->ineligible();
            }
            $snapshot = $this->frozen->snapshot($order);
            $bill = $this->frozen->present($snapshot);
            if ($snapshot['discounts'] !== [] || $snapshot['comps'] !== [] || $bill['discount_total_baisas'] !== 0
                || $bill['comp_total_baisas'] !== 0 || $bill['items'] === [] || count($bill['items']) > 1000) {
                throw $this->ineligible();
            }
            // Ownership comes from ids, not a product/quantity subtraction from
            // the whole bill (which also contains customer/other-device lines).
            $rounds = QrOrderRound::query()->where('order_id', $order->id)->orderBy('id')->get();
            $owners = [];
            foreach ($rounds as $round) {
                foreach ($round->priced_lines ?? [] as $line) {
                    if (isset($line['order_item_id'])) {
                        $id = (int) $line['order_item_id'];
                        if (isset($owners[$id])) {
                            throw $this->changed();
                        }
                        $owners[$id] = (int) $round->id;
                    }
                }
            }
            $items = [];
            foreach ($bill['items'] as $item) {
                $items[$item['id']] = $this->item($item);
            }
            if ($bill['subtotal_baisas'] !== array_sum(array_column($items, 'line_total_baisas'))
                || $bill['tax_total_baisas'] < 0 || $bill['grand_total_baisas'] !== $bill['subtotal_baisas'] + $bill['tax_total_baisas']) {
                throw $this->changed();
            }
            $evidence = $values['kind'] === 'staff_rounds'
                ? $this->staffRounds($current, $order, $seat, $values['event_ids'], $rounds->keyBy('id')->all(), $items, $owners)
                : [$this->legacyHold($current, $order, $items, $owners)];
            if ($values['kind'] === 'staff_rounds' && array_diff_key($items, $owners) !== []) {
                // Existing totals refresh sums accepted rounds. An unowned
                // legacy baseline cannot safely receive a delta yet.
                throw $this->refusal('draft_proof_baseline_required', 'Legacy bill items need an explicit baseline before another staff round. Keep the draft.');
            }
            if ($values['kind'] === 'staff_rounds') {
                $this->assertRoundBalances($rounds->all(), $bill);
            }

            return [
                'proof_policy' => 'same_bill_draft_v1', 'read_only' => true, 'archive_authorized' => false,
                'table_id' => $tableId, 'table_label' => $detail['table']['label'], 'device_id' => (int) $current->id,
                'order_uuid' => $order->uuid, 'table_session_uuid' => $seat->uuid, 'kind' => $values['kind'],
                'delta_policy' => $values['kind'] === 'staff_rounds' ? 'proven_local_rounds_only' : 'blocked_until_baseline_adoption',
                'bill' => $bill, 'acknowledged' => $evidence,
            ];
        });
    }

    private function stationAdopted(Order $order, Device $device): bool
    {
        if ($order->source !== Order::SOURCE_QR_WEB || $order->qr_session_id === null || $order->device_id === null) {
            return false;
        }

        return QrSession::query()->whereKey($order->qr_session_id)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->where('table_id', $order->table_id)
            ->where('table_session_id', $order->table_session_id)->where('device_id', $order->device_id)
            ->whereNull('released_at')->exists()
            && Device::query()->whereKey($order->device_id)->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->first()?->isPaymentStation() === true;
    }

    private function assertRoundBalances(array $rounds, array $bill): void
    {
        $subtotal = $tax = $total = 0;
        foreach ($rounds as $round) {
            if ($round->status !== QrOrderRound::STATUS_ACCEPTED) {
                continue;
            }
            $lines = $round->priced_lines;
            if (! is_array($lines) || ! array_is_list($lines) || $lines === []) {
                throw $this->changed();
            }
            $gross = 0;
            foreach ($lines as $line) {
                if (! is_array($line) || ! is_int($line['line_total_baisas'] ?? null)
                    || $line['line_total_baisas'] < 0 || ($line['line_discount_baisas'] ?? 0) !== 0) {
                    throw $this->changed();
                }
                $gross += $line['line_total_baisas'];
            }
            if ($gross !== (int) $round->subtotal_baisas || $round->tax_baisas < 0
                || $gross + (int) $round->tax_baisas !== (int) $round->total_baisas) {
                throw $this->changed();
            }
            $subtotal += $gross;
            $tax += (int) $round->tax_baisas;
            $total += (int) $round->total_baisas;
        }
        if ($subtotal !== $bill['subtotal_baisas'] || $tax !== $bill['tax_total_baisas'] || $total !== $bill['grand_total_baisas']) {
            throw $this->changed();
        }
    }

    private function staffRounds(Device $device, Order $order, TableSession $seat, array $ids, array $rounds, array $items, array $owners): array
    {
        $proofs = [];
        $seen = [];
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $event = $this->event($device, $id, 'table.session.round');
            $payload = $event->payload_json;
            $result = $event->result_json;
            if (! is_int($result['round_id'] ?? null) || $result['round_id'] < 1) {
                throw $this->changed();
            }
            $round = $rounds[$result['round_id']] ?? null;
            if ($round === null || isset($seen[$round->id]) || $round->qr_session_id !== null
                || (int) $round->table_session_id !== (int) $seat->id || $round->origin_table_session_id !== null
                || $round->status !== QrOrderRound::STATUS_ACCEPTED
                || ! in_array($result['outcome'] ?? null, ['appended', 'seating_created', 'replayed'], true)
                || ($result['order_uuid'] ?? null) !== $order->uuid || ($result['table_session_uuid'] ?? null) !== $seat->uuid
                || ($result['round_status'] ?? null) !== QrOrderRound::STATUS_ACCEPTED
                || ($result['round_no'] ?? null) !== (int) $round->round_no
                || ($result['total_baisas'] ?? null) !== (int) $round->total_baisas
                || ($payload['client_request_id'] ?? null) !== $round->client_request_id
                || (int) ($payload['table_id'] ?? 0) !== (int) $seat->table_id
                || ($result['table_id'] ?? null) !== (int) $seat->table_id
                || ! is_string($payload['seating_key'] ?? null)
                || ($result['seating_key'] ?? null) !== $payload['seating_key']) {
                throw $this->changed();
            }
            $seen[$round->id] = true;
            $requested = $payload['lines'] ?? [];
            $priced = $round->priced_lines ?? [];
            if (! is_array($requested) || ! array_is_list($requested) || $requested === []
                || ! is_array($priced) || ! array_is_list($priced) || count($requested) !== count($priced)) {
                throw $this->changed();
            }
            $lines = [];
            foreach ($priced as $index => $line) {
                if (! is_array($line) || ! is_array($requested[$index])
                    || ! is_int($line['order_item_id'] ?? null) || $line['order_item_id'] < 1) {
                    throw $this->changed();
                }
                $itemId = $line['order_item_id'] ?? null;
                $item = $items[$itemId ?? 0] ?? null;
                if ($item === null || ($owners[$itemId] ?? null) !== (int) $round->id
                    || isset($line['held_reason']) || ! empty($line['cancellations']) || ! empty($line['cancelled_qty'])
                    || ($line['line_index'] ?? null) !== $index
                    || $this->roundItem($line) !== $item
                    || $this->requestLine($requested[$index] ?? []) !== $this->requestLine([
                        'product_id' => $item['product_id'], 'qty' => $item['qty'], 'notes' => $item['notes'],
                        'addon_ids' => array_column($item['addons'], 'id'),
                    ])) {
                    throw $this->changed();
                }
                $lines[] = ['order_item_id' => (int) $itemId] + $item;
            }
            $proofs[] = [
                'client_event_id' => $event->client_event_id, 'client_request_id' => $round->client_request_id,
                'seating_key' => $payload['seating_key'], 'round_id' => (int) $round->id,
                'round_no' => (int) $round->round_no, 'lines' => $lines,
            ];
        }

        return $proofs;
    }

    private function legacyHold(Device $device, Order $order, array $items, array $owners): array
    {
        $event = $this->event($device, $order->client_event_id, 'order.hold');
        $stored = $event->payload_json['order'] ?? [];
        $ack = $event->result_json;
        if (! is_array($stored) || ($stored['uuid'] ?? null) !== $order->uuid || ($stored['order_type'] ?? null) !== 'dine_in'
            || (int) ($stored['table_id'] ?? 0) !== (int) $order->table_id
            || ! in_array($stored['source'] ?? null, ['main_pos', 'handheld'], true)
            || ($ack['order_uuid'] ?? null) !== $order->uuid || ($ack['order_id'] ?? null) !== (int) $order->id
            || ! in_array($ack['status'] ?? null, ['created', 'updated'], true) || ($ack['order_status'] ?? null) !== 'held'
            || (int) ($stored['discount_total_baisas'] ?? 0) !== 0 || (int) ($stored['comp_total_baisas'] ?? 0) !== 0
            || ! empty($stored['discounts']) || ! empty($stored['comps']) || ! empty($stored['joined_table_ids'])) {
            throw $this->changed();
        }
        $unowned = array_diff_key($items, $owners);
        $requested = $stored['lines'] ?? [];
        if (! is_array($requested) || ! array_is_list($requested) || $requested === [] || count($requested) !== count($unowned)
            || count(array_filter($requested, 'is_array')) !== count($requested)) {
            throw $this->changed();
        }
        // The held event did not retain item ids. Exact multiset equality is
        // evidence for an unchanged cache, NEVER permission to retire it or
        // derive a delta before legacy baseline accounting is resolved.
        $expected = array_map(fn (array $line): string => json_encode($this->legacyLine($line), JSON_THROW_ON_ERROR), $requested);
        $actual = array_map(fn (array $line): string => json_encode($this->legacyLine([
            'product_id' => $line['product_id'], 'qty' => $line['qty'], 'notes' => $line['notes'],
            'unit_price_baisas' => $line['unit_price_baisas'], 'line_total_baisas' => $line['line_total_baisas'],
            'addons' => array_map(static fn (array $addon): array => [
                'add_on_id' => $addon['id'], 'price_delta_baisas' => $addon['price_delta_baisas'],
            ], $line['addons']),
        ]), JSON_THROW_ON_ERROR), array_values($unowned));
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual || ($stored['subtotal_baisas'] ?? null) !== array_sum(array_column($unowned, 'line_total_baisas'))) {
            throw $this->changed();
        }

        return ['client_event_id' => $event->client_event_id, 'lines' => array_values(array_map(
            static fn (int $id, array $line): array => ['order_item_id' => $id] + $line, array_keys($unowned), array_values($unowned)))];
    }

    private function event(Device $device, ?string $id, string $type): SyncEvent
    {
        $event = $id === null ? null : SyncEvent::query()->where('client_event_id', $id)->where('device_id', $device->id)
            ->where('event_type', $type)->where('ack_status', SyncEvent::STATUS_PROCESSED)->whereNotNull('processed_at')->first();
        if ($event === null || ! is_array($event->payload_json) || ! is_array($event->result_json)) {
            throw $this->refusal('draft_proof_evidence_missing', 'A matching acknowledgement is unavailable. Keep the local draft and sync history.');
        }

        return $event;
    }

    private function item(array $item): array
    {
        $qty = $item['qty'];
        if (! is_numeric($qty) || (float) $qty !== (float) (int) $qty || (int) $qty < 1 || (int) $qty > 999
            || $item['status'] !== 'open' || $item['line_discount_baisas'] !== 0 || (int) $item['product_id'] < 1
            || $item['unit_price_baisas'] < 0 || $item['line_total_baisas'] !== $item['unit_price_baisas'] * (int) $qty) {
            throw $this->changed();
        }
        $addons = $item['addons'];
        usort($addons, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        if (count(array_unique(array_column($addons, 'id'))) !== count($addons)) {
            throw $this->changed();
        }

        return ['product_id' => (int) $item['product_id'], 'qty' => (int) $qty, 'name' => $item['name'],
            'notes' => $item['notes'] ?? '', 'unit_price_baisas' => $item['unit_price_baisas'],
            'line_total_baisas' => $item['line_total_baisas'], 'addons' => $addons];
    }

    private function roundItem(array $line): array
    {
        foreach (['product_id', 'qty', 'product_name', 'unit_price_baisas', 'line_total_baisas', 'line_discount_baisas'] as $key) {
            if (! array_key_exists($key, $line)) {
                throw $this->changed();
            }
        }

        return $this->item(['status' => 'open', 'name' => $line['product_name'], 'notes' => $line['notes'] ?? '',
            'addons' => array_map(static fn (array $addon): array => ['id' => $addon['add_on_id'],
                'name' => $addon['name'], 'price_delta_baisas' => $addon['price_delta_baisas']], $line['addons'] ?? []),
        ] + $line);
    }

    private function requestLine(array $line): array
    {
        $qty = $line['qty'] ?? 0;
        if (! is_numeric($qty) || (float) $qty !== (float) (int) $qty || (int) $qty < 1 || (int) $qty > 999
            || ! is_numeric($line['product_id'] ?? null) || (int) $line['product_id'] < 1
            || ! is_array($line['addon_ids'] ?? []) || ! array_is_list($line['addon_ids'] ?? [])
            || (isset($line['notes']) && ! is_string($line['notes']))) {
            throw $this->changed();
        }
        foreach ($line['addon_ids'] ?? [] as $id) {
            if (! is_numeric($id) || (float) $id !== (float) (int) $id || (int) $id < 1) {
                throw $this->changed();
            }
        }
        $addons = array_map('intval', $line['addon_ids'] ?? []);
        if (count(array_unique($addons)) !== count($addons)) {
            throw $this->changed();
        }
        sort($addons, SORT_NUMERIC);

        return ['product_id' => (int) $line['product_id'], 'qty' => (int) $qty,
            'notes' => $line['notes'] ?? '', 'addon_ids' => $addons];
    }

    private function legacyLine(array $line): array
    {
        $request = $this->requestLine($line);
        if (! is_int($line['unit_price_baisas'] ?? null) || $line['unit_price_baisas'] < 0
            || ($line['line_total_baisas'] ?? null) !== $line['unit_price_baisas'] * $request['qty']
            || ($line['line_discount_baisas'] ?? 0) !== 0) {
            throw $this->changed();
        }
        $rawAddons = $line['addons'] ?? [];
        if (! is_array($rawAddons) || ! array_is_list($rawAddons)) {
            throw $this->changed();
        }
        $addons = [];
        foreach ($rawAddons as $addon) {
            if (! is_array($addon) || ! is_numeric($addon['add_on_id'] ?? null)
                || (float) $addon['add_on_id'] !== (float) (int) $addon['add_on_id'] || (int) $addon['add_on_id'] < 1
                || ! is_int($addon['price_delta_baisas'] ?? 0) || ($addon['price_delta_baisas'] ?? 0) < 0) {
                throw $this->changed();
            }
            $addons[] = ['id' => (int) $addon['add_on_id'], 'price_delta_baisas' => $addon['price_delta_baisas'] ?? 0];
        }
        if (count(array_unique(array_column($addons, 'id'))) !== count($addons)) {
            throw $this->changed();
        }
        usort($addons, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $request + ['unit_price_baisas' => $line['unit_price_baisas'],
            'line_total_baisas' => $line['line_total_baisas'], 'addons' => $addons];
    }

    private function changed(): QrDineInException
    {
        return $this->refusal('draft_proof_evidence_changed', 'Saved acknowledgements and bill lines disagree. Keep the local draft for separate review.');
    }

    private function ineligible(): QrDineInException
    {
        return $this->refusal('draft_proof_ineligible', 'Use an open, unjoined bill without payment, transfer or unresolved accounting evidence. Keep the local draft.');
    }

    private function refusal(string $code, string $message): QrDineInException
    {
        return new QrDineInException($code, 409, $message);
    }
}
