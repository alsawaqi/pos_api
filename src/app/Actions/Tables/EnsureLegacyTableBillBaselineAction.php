<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Support\Money;
use App\Support\Pricing\BillMoney;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Record original held items ONCE; never copy/reprice/print/consume them. */
final class EnsureLegacyTableBillBaselineAction
{
    public function __construct(
        private readonly FrozenLegacyTableBill $frozen,
        private readonly ReadTableDraftProofAction $evidence,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /**
     * Internal writer only. Caller MUST hold this bill's order lock. Every
     * shared writer serializes there; do not acquire earlier device/table locks
     * or lock a historical SyncEvent (dispatcher locks event before order).
     * Queue the journal, never flush before the caller's remaining writes.
     */
    public function handle(Order $order): ?QrOrderRound
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Legacy baseline requires the existing locked bill transaction.');
        }
        if ($order->order_type !== 'dine_in') {
            return null;
        }
        if ($order->table_session_id === null) {
            if ($order->client_event_id !== null && (TableSession::query()->where('order_id', $order->id)->exists()
                || QrOrderRound::query()->where('order_id', $order->id)->whereNotNull('table_session_id')->exists())) {
                throw $this->refusal();
            }

            return null;
        }
        $snapshot = $this->frozen->snapshot($order);
        $owners = [];
        $rounds = QrOrderRound::query()->where('order_id', $order->id)->orderBy('id')->get();
        // Round-origin bills have no legacy hold anchor. Preserve their shipped
        // pre-item-link review/cancellation rules, but never infer a missing
        // held baseline from the source string. If actual items exist, their
        // gross and accepted money must balance before another writer runs.
        if ($order->client_event_id === null) {
            if ($snapshot['items'] === []) {
                return null;
            }
            $accepted = $rounds->where('status', QrOrderRound::STATUS_ACCEPTED);
            if ((int) $accepted->sum('subtotal_baisas') !== Money::toBaisas($order->subtotal)
                || ! $this->totals->matchesHeader($order)
                || array_sum(array_map(static fn (array $item): int => Money::toBaisas($item['line_total']), $snapshot['items']))
                    !== Money::toBaisas($order->subtotal)) {
                throw $this->refusal();
            }

            return null;
        }
        foreach ($rounds as $round) {
            if (! is_array($round->priced_lines) || ! array_is_list($round->priced_lines)) {
                throw $this->refusal();
            }
            foreach ($round->priced_lines ?? [] as $line) {
                if (! is_array($line)) {
                    throw $this->refusal();
                }
                if (isset($line['order_item_id'])) {
                    $id = $line['order_item_id'];
                    if (! is_int($id) || $id < 1 || isset($owners[$id]) || $round->status !== QrOrderRound::STATUS_ACCEPTED) {
                        throw $this->refusal();
                    }
                    $owners[$id] = (int) $round->id;
                }
            }
        }
        $unowned = array_filter($snapshot['items'], static fn (array $item): bool => ! isset($owners[(int) $item['id']]));
        if ($unowned === []) {
            $this->assertReadyForCharge($order);

            return null;
        }
        TableBillAdjustmentView::refuseUnsupported($order);
        $seat = TableSession::query()->whereKey($order->table_session_id)->where('order_id', $order->id)
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('table_id', $order->table_id)->first();
        if ($seat === null || $order->status !== Order::STATUS_OPEN || $order->closed_at !== null
            || $seat->status !== TableSession::STATUS_OPEN || $seat->billing_at !== null || $seat->merged_into_id !== null
            || $order->client_event_id === null || $snapshot['tables'] !== [] || $snapshot['discounts'] !== []
            || $snapshot['comps'] !== [] || Money::toBaisas($order->discount_total) !== 0
            || Money::toBaisas($order->comp_total) !== 0
            || TableSession::query()->where('merged_into_id', $seat->id)->whereIn('status', TableSession::LIVE_STATUSES)->exists()) {
            throw $this->refusal();
        }
        foreach (['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas', 'charge_claimed_at',
            'charge_deadline_at', 'charge_outcome', 'transferred_to_device_id', 'transferred_from_device_id',
            'transferred_at', 'delivery_provider_id'] as $field) {
            if ($order->$field !== null) {
                throw $this->refusal();
            }
        }
        foreach (['pos_payments', 'pos_loyalty_transactions', 'pos_sale_commissions', 'pos_roundup_donations'] as $ledger) {
            if (DB::table($ledger)->where('order_id', $order->id)->exists()) {
                throw $this->refusal();
            }
        }
        foreach (['pos_stock_movements', 'pos_product_stock_movements'] as $ledger) {
            if (DB::table($ledger)->where('reference_type', 'pos_orders')->where('reference_id', $order->id)->exists()) {
                throw $this->refusal();
            }
        }
        try {
            $proof = $this->evidence->legacyAccountingEvidence($order, $snapshot, $owners);
        } catch (QrDineInException) {
            throw $this->refusal();
        }
        $items = array_column($snapshot['items'], null, 'id');
        $accepted = ['subtotal_baisas' => 0, 'tax_baisas' => 0, 'total_baisas' => 0];
        $seen = [];
        foreach ($rounds as $round) {
            if ($round->status !== QrOrderRound::STATUS_ACCEPTED) {
                if (collect($round->priced_lines ?? [])->contains(static fn (array $line): bool => isset($line['order_item_id']))) {
                    throw $this->refusal();
                }

                continue;
            }
            if ((int) $round->table_session_id !== (int) $seat->id || $round->origin_table_session_id !== null
                || ! is_array($round->priced_lines) || $round->priced_lines === [] || $round->tax_baisas < 0) {
                throw $this->refusal();
            }
            $gross = 0;
            foreach ($round->priced_lines as $line) {
                $id = $line['order_item_id'] ?? null;
                $item = $items[$id ?? 0] ?? null;
                if (! is_int($id) || isset($seen[$id]) || $item === null || isset($line['held_reason'])
                    || ! empty($line['cancellations']) || ! empty($line['cancelled_qty'])
                    || ($line['line_discount_baisas'] ?? 0) !== 0
                    || ($line['product_id'] ?? null) !== (int) $item['product_id']
                    || ($line['product_name'] ?? null) !== $item['product_name_snapshot']
                    || ($line['qty'] ?? null) !== (int) $item['qty']
                    || ($line['notes'] ?? '') !== ($item['notes'] ?? '')
                    || ($line['unit_price_baisas'] ?? null) !== Money::toBaisas($item['unit_price_snapshot'])
                    || ($line['line_total_baisas'] ?? null) !== Money::toBaisas($item['line_total'])
                    || ! $this->addonsMatch($line, $snapshot, $id)) {
                    throw $this->refusal();
                }
                $seen[$id] = true;
                $gross += $line['line_total_baisas'];
            }
            if ($gross !== (int) $round->subtotal_baisas
                || BillMoney::total($gross, (int) $round->tax_baisas, (bool) $order->prices_include_tax) !== (int) $round->total_baisas) {
                throw $this->refusal();
            }
            foreach ($accepted as $field => $value) {
                $accepted[$field] += (int) $round->$field;
            }
        }
        $expected = array_map(static fn (string $field): int => $proof[$field] + $accepted[$field], array_keys($accepted));
        $current = [Money::toBaisas($order->subtotal), Money::toBaisas($order->tax_total), Money::toBaisas($order->grand_total)];
        // Admit only intact accounting or the precisely reproduced round-only
        // refresh defect. Any other discrepancy needs separate human review.
        if ($current !== $expected && ($rounds->where('status', QrOrderRound::STATUS_ACCEPTED)->isEmpty()
            || $current !== array_values($accepted))) {
            throw $this->refusal();
        }
        $requestId = 'legacy-baseline:'.$order->uuid;
        if (QrOrderRound::query()->where('table_session_id', $seat->id)->where('client_request_id', $requestId)->exists()) {
            // A client collision is not authority to reuse or overwrite it.
            throw $this->refusal();
        }
        $lines = [];
        foreach ($proof['lines'] as $line) {
            $addons = array_map(static fn (array $addon): array => ['add_on_id' => $addon['id'],
                'name' => $addon['name'], 'price_delta_baisas' => $addon['price_delta_baisas']], $line['addons']);
            $lines[] = ['line_index' => count($lines), 'product_id' => $line['product_id'], 'product_name' => $line['name'],
                'qty' => $line['qty'], 'notes' => $line['notes'], 'addons' => $addons,
                'unit_price_baisas' => $line['unit_price_baisas'],
                'base_price_baisas' => $line['unit_price_baisas'] - array_sum(array_column($addons, 'price_delta_baisas')),
                'line_discount_baisas' => 0, 'line_total_baisas' => $line['line_total_baisas'],
                'order_item_id' => $line['order_item_id'], 'accounting_only' => true];
        }
        $baseline = QrOrderRound::query()->create([
            'qr_session_id' => null, 'table_session_id' => $seat->id, 'order_id' => $order->id,
            'round_no' => ((int) $rounds->max('round_no')) + 1, 'client_request_id' => $requestId,
            'status' => QrOrderRound::STATUS_ACCEPTED, 'priced_lines' => $lines, 'confirm_payload' => null,
            'accepted_seq' => null, 'needs_review' => false, 'subtotal_baisas' => $proof['subtotal_baisas'],
            'tax_baisas' => $proof['tax_baisas'], 'total_baisas' => $proof['total_baisas'],
            'submitted_at' => $order->opened_at, 'resolved_at' => now(), 'resolved_by_device_id' => null,
        ]);
        if ($current !== $expected) {
            $this->totals->handle($order);
        }
        $this->journal->handle($seat, 'attached', ['action' => 'legacy_bill_baselined',
            'order_uuid' => $order->uuid, 'round_id' => (int) $baseline->id,
            'client_event_id' => $proof['client_event_id'], 'original_device_id' => $proof['device_id'],
            'order_item_ids' => array_column($lines, 'order_item_id'), 'previous_totals_baisas' => $current,
            'corrected_totals_baisas' => $expected, 'kitchen_submission' => false]);

        return $baseline;
    }

    /** Read-only charge gate: never import/reprice an already quoted bill. */
    public function assertReadyForCharge(Order $order): void
    {
        if ($order->order_type !== 'dine_in' || $order->client_event_id === null) {
            return;
        }
        $linked = $order->table_session_id !== null || TableSession::query()->where('order_id', $order->id)->exists()
            || QrOrderRound::query()->where('order_id', $order->id)->whereNotNull('table_session_id')->exists();
        if (! $linked) {
            return;
        }
        if ($order->table_session_id === null) {
            throw $this->refusal();
        }
        $snapshot = $this->frozen->snapshot($order);
        $items = array_column($snapshot['items'], null, 'id');
        $seen = [];
        $subtotal = $tax = $total = 0;
        foreach (QrOrderRound::query()->where('order_id', $order->id)->get() as $round) {
            if (! is_array($round->priced_lines) || ! array_is_list($round->priced_lines)) {
                throw $this->refusal();
            }
            $gross = 0;
            foreach ($round->priced_lines as $line) {
                if (! is_array($line)) {
                    throw $this->refusal();
                }
                if ($round->status !== QrOrderRound::STATUS_ACCEPTED) {
                    if (isset($line['order_item_id'])) {
                        throw $this->refusal();
                    }

                    continue;
                }
                if (isset($line['held_reason']) && ($line['held_disposition'] ?? null) === 'dropped_at_review') {
                    continue;
                }
                $id = $line['order_item_id'] ?? null;
                if (! is_int($id) || ! isset($items[$id]) || isset($seen[$id])
                    || ! is_int($line['qty'] ?? null) || ! is_int($line['cancelled_qty'] ?? 0)
                    || ! is_int($line['unit_price_baisas'] ?? null)) {
                    throw $this->refusal();
                }
                $item = $items[$id];
                $qty = $line['qty'] - ($line['cancelled_qty'] ?? 0);
                if ($qty < 0 || $line['unit_price_baisas'] < 0 || (float) $item['qty'] !== (float) $qty
                    || ($line['product_id'] ?? null) !== (int) $item['product_id']
                    || ($line['product_name'] ?? null) !== $item['product_name_snapshot']
                    || ($line['notes'] ?? '') !== ($item['notes'] ?? '')
                    || $line['unit_price_baisas'] !== Money::toBaisas($item['unit_price_snapshot'])
                    || Money::toBaisas($item['line_total']) !== $line['unit_price_baisas'] * $qty
                    || ! $this->addonsMatch($line, $snapshot, $id)) {
                    throw $this->refusal();
                }
                $seen[$id] = true;
                $gross += $line['unit_price_baisas'] * $qty;
            }
            if ($round->status === QrOrderRound::STATUS_ACCEPTED) {
                if ($gross !== (int) $round->subtotal_baisas || min($round->tax_baisas, $round->total_baisas) < 0
                    || $round->total_baisas > BillMoney::total($gross, (int) $round->tax_baisas, (bool) $order->prices_include_tax)) {
                    throw $this->refusal();
                }
                $subtotal += $gross;
                $tax += (int) $round->tax_baisas;
                $total += (int) $round->total_baisas;
            }
        }
        $amounts = $this->totals->amounts($order);
        if (array_diff_key($items, $seen) !== [] || $subtotal !== $amounts['subtotal']
            || $tax !== $amounts['tax'] || $total !== $amounts['total']
            || ! $this->totals->matchesHeader($order, $amounts)) {
            throw $this->refusal();
        }
    }

    private function addonsMatch(array $line, array $snapshot, int $itemId): bool
    {
        $addons = $line['addons'] ?? [];
        if (! is_array($addons) || ! array_is_list($addons)) {
            return false;
        }
        $expected = [];
        foreach ($addons as $addon) {
            if (! is_array($addon) || ! is_int($addon['add_on_id'] ?? null)
                || ! is_string($addon['name'] ?? null) || ! is_int($addon['price_delta_baisas'] ?? null)) {
                return false;
            }
            $expected[] = [$addon['add_on_id'], $addon['name'], $addon['price_delta_baisas']];
        }
        $actual = [];
        foreach ($snapshot['addons'] as $addon) {
            if ((int) $addon['order_item_id'] === $itemId) {
                $actual[] = [(int) $addon['add_on_id'], $addon['add_on_name_snapshot'], Money::toBaisas($addon['price_delta_snapshot'])];
            }
        }
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    /** Reuse the shipped bill_unpaid review path; never retry-wedge a business refusal. */
    public static function syncRefusal(QrDineInException $exception, array $payload): array
    {
        if ($exception->codeName !== 'legacy_baseline_review_required') {
            throw $exception;
        }

        return ['outcome' => 'bill_unpaid', 'refusal_code' => $exception->codeName, 'message' => $exception->getMessage(),
            'needs_review' => true, 'event_id' => null, 'table_id' => (int) $payload['table_id'],
            'seating_key' => $payload['seating_key'], 'table_session_uuid' => null, 'winner_table_session_uuid' => null,
            'order_uuid' => null, 'temp_reference' => null];
    }

    private function refusal(): QrDineInException
    {
        return new QrDineInException('legacy_baseline_review_required', 409,
            'The original held bill needs accounting review. Keep the draft; no items or payment were changed.');
    }
}
