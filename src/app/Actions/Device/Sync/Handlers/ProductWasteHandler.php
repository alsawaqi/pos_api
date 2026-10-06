<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\BranchProduct;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Models\TableSessionEvent;
use App\Support\Recipes\OrderTypes;
use App\Support\Recipes\PrepExploder;
use App\Support\Recipes\RecipeInForce;
use App\Support\StockDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Process a `product.waste` sync event — the device records that one or more
 * COOKED or READY/BOUGHT-IN products on this branch's shelf were wasted.
 *
 * The product-units parallel of the `stock.count` shortfall path: per line a
 * signed-negative 'waste' ProductStockMovement is written (with the WasteReason
 * + a per-unit cost FROZEN at this moment, {@see unitCost()}) and the branch
 * shelf (pos_branch_product.stock_qty) is decremented. The merchant Loss/Waste
 * report surfaces it with no extra wiring.
 *
 * LAUNCH-P3 fix order 1 K3 (owner decision 2026-10-02: waste follows the
 * selling rule) — waste is NEVER refused on the shelf numbers. A waste larger
 * than the shelf count takes the count below zero; with no count at this
 * branch (no row, or a NULL stock_qty) the waste is still recorded and no
 * shelf moves (a row is never created: rows also scope availability). Either
 * case is reported back in result.shelf_shortfalls (additive key, present
 * only when non-empty). A proved prepared-table cancellation still caps its
 * own waste at the cancelled quantity — an accounting identity, not a stock
 * number.
 *
 * Wastage is LOSS-tracking, NOT an expense — the cost was already booked at
 * purchase (unit) or production (cooked) under the cash model.
 *
 * The whole event is atomic: a bad line (unknown/ineligible product) fails the
 * entire submission, mirroring the ingredient stock-count flow.
 */
class ProductWasteHandler implements SyncEventHandler
{
    /** Mirror of pos_merchant's App\Enums\WasteReason (no enum exists here). */
    private const REASONS = ['expired', 'spoiled', 'broken', 'dropped', 'contamination', 'other'];

    public function handle(SyncEvent $event, Device $device, ?array $preparedQuickCancellation = null): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.reason' => ['required', 'string', Rule::in(self::REASONS)],
            'note' => ['sometimes', 'nullable', 'string'],
            'staff_id' => ['sometimes', 'nullable', 'integer'],
            'wasted_at' => ['sometimes', 'nullable', 'string'],
            'table_cancellation_request_id' => ['sometimes', 'uuid'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid product.waste payload: '.implode('; ', $validator->errors()->all()));
        }

        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;
        // UTC like the ledger (an offset stamp keeps its instant, fix order 1 K6).
        $wastedAt = isset($payload['wasted_at'])
            ? Carbon::parse((string) $payload['wasted_at'])->utc()
            : ($event->client_timestamp ?? now());
        // Phase 4 — the recorded_by staff id (an audit column) must be a staff
        // member of the device's own company; withTrashed keeps offline-queued
        // events by a since-terminated recorder settling.
        $staffId = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
        TenantReferenceGuard::assertStaffInTenant($device, $staffId, 'product.waste references a staff member outside the device tenant');
        $note = isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;

        // Resolve + validate every line BEFORE writing, so a bad line fails the
        // whole event (atomic, like the merchant flow).
        $resolved = [];
        foreach ($payload['lines'] as $line) {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->find((int) $line['product_id']);
            if ($product === null) {
                throw new RuntimeException('unknown product in product.waste: '.$line['product_id']);
            }
            // Only shelf-tracked products hold a branch count that can be wasted.
            if (! in_array($product->stock_mode, ['unit', 'cooked'], true)) {
                throw new RuntimeException('product '.$product->id.' does not hold branch stock that can be wasted');
            }
            $reason = (string) $line['reason'];
            if ($reason === 'other' && $note === null) {
                throw new RuntimeException('a note is required when a waste reason is "other"');
            }
            $resolved[] = ['product' => $product, 'qty' => round((float) $line['qty'], 3), 'reason' => $reason];
        }

        return DB::transaction(function () use ($event, $device, $payload, $resolved, $companyId, $branchId, $staffId, $note, $wastedAt, $preparedQuickCancellation): array {
            // A proved table cancellation caps its own waste (throws past it)
            // and is stamped on the result. $preparedQuickCancellation is an
            // internal capability only: the QR cancellation action holds the
            // scoped order/items, caps these quantities and commits its audit
            // atomically. No HTTP/sync payload field can supply this argument.
            $authority = $this->preparedTableWasteAuthority($event, $device, $payload, $wastedAt);
            $wastedLines = 0;
            $totalQty = 0.0;
            $shortfalls = [];

            foreach ($resolved as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $qty = $line['qty'];

                $row = BranchProduct::query()
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();
                $counted = $row?->stock_qty !== null;
                $available = $counted ? (float) $row->stock_qty : 0.0;

                // K3 — sell, but warn: report a waste the shelf count does not
                // cover; never refuse it.
                if (! $counted || $qty > $available + 1e-9) {
                    $shortfalls[] = [
                        'product_id' => (int) $product->id,
                        'name' => (string) $product->name,
                        'wasted' => number_format($qty, 3, '.', ''),
                        'on_shelf' => $counted ? number_format($available, 3, '.', '') : null,
                    ];
                }

                ProductStockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'movement_type' => ProductStockMovement::TYPE_WASTE,
                    'reason' => $line['reason'],
                    'quantity' => number_format(-$qty, 3, '.', ''),
                    'unit_cost' => $this->unitCost($product, $branchId, $wastedAt),
                    'recorded_by_pos_staff_id' => $staffId,
                    'note' => $note,
                    'occurred_at' => $wastedAt,
                    'created_at' => now(),
                ]);

                if ($counted) {
                    $row->stock_qty = $available - $qty;
                    $row->save();
                }

                $wastedLines++;
                $totalQty += $qty;
            }

            return [
                ...($authority === null ? [] : ['table_cancellation_waste' => $authority]),
                ...($preparedQuickCancellation === null ? [] : ['quick_cancellation_waste' => $preparedQuickCancellation]),
                'wasted_lines' => $wastedLines,
                'total_qty' => number_format($totalQty, 3, '.', ''),
                ...($shortfalls === [] ? [] : ['shelf_shortfalls' => $shortfalls]),
            ];
        });
    }

    /**
     * An existing cancellation receipt is the authority, not a free-text note.
     * Legacy till batches did not carry the request ID: their cancellation and
     * waste were generated together with the same timestamp. Match the one
     * processed, same-device cancellation exactly; ambiguity fails closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function preparedTableWasteAuthority(SyncEvent $event, Device $device, array $payload, Carbon $wastedAt): ?array
    {
        if (count($payload['lines']) !== 1 || $payload['lines'][0]['reason'] !== 'other') {
            return null;
        }
        $line = $payload['lines'][0];
        $requestId = $payload['table_cancellation_request_id'] ?? null;
        if ($requestId === null && ! str_starts_with((string) ($payload['note'] ?? ''), 'cancelled after preparation — table ')) {
            return null;
        }
        // The table journal and sync ACK can both prove the same cancellation.
        // Lock the common journal first whenever it exists, even if the sync
        // receipt below supplies the proof, so provenance cannot split its cap.
        $records = $requestId === null ? collect() : TableSessionEvent::query()
            ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->where('device_id', $device->id)->where('event_type', 'round_resolved')
            ->where('payload->action', 'line_cancelled')
            ->where('payload->client_request_id', $requestId)->lockForUpdate()->get()
            ->filter(fn (TableSessionEvent $c): bool => ($c->payload['prepared'] ?? false) === true
                && (int) ($c->payload['product_id'] ?? 0) === (int) $line['product_id']);
        $query = SyncEvent::query()->where('device_id', $device->id)
            ->where('event_type', 'table.session.cancel_line')->where('ack_status', SyncEvent::STATUS_PROCESSED);
        if ($requestId !== null) {
            $query->where('client_event_id', $requestId);
        } else {
            $query->where('client_timestamp', $event->client_timestamp ?? $wastedAt);
        }
        // Cancellation row lock serializes distinct waste events. Consumption
        // is append-only in their processed results, in the same transaction.
        $matches = $query->lockForUpdate()->get()->filter(function (SyncEvent $cancel) use ($line, $payload, $device, $requestId): bool {
            $p = (array) $cancel->payload_json;
            $tableMatches = ! isset($p['table_id']) || Table::query()->whereKey($p['table_id'])
                ->where('company_id', $device->company_id)
                ->whereIn('floor_id', Floor::query()->select('id')->where('company_id', $device->company_id)
                    ->where('branch_id', $device->branch_id))->exists();

            return $tableMatches && ($p['prepared'] ?? false) === true
                && (int) ($p['product_id'] ?? 0) === (int) $line['product_id']
                && ($requestId !== null || ($p['staff_id'] ?? null) === ($payload['staff_id'] ?? null))
                && in_array($cancel->result_json['outcome'] ?? null, ['cancelled', 'replayed', 'bill_terminal', 'nothing_to_cancel'], true);
        });
        $cancel = $matches->count() === 1 ? $matches->first() : null;
        if ($cancel !== null) {
            $authority = ['source' => 'sync', 'id' => $cancel->id,
                'request_id' => $cancel->client_event_id, 'qty' => (float) $cancel->payload_json['qty']];
            $at = $cancel->client_timestamp;
            $legacyStaffId = $cancel->payload_json['staff_id'] ?? null;
        } elseif ($requestId !== null && $matches->isEmpty()) {
            if ($records->count() !== 1) {
                return null;
            }
            $cancel = $records->first();
            $authority = ['source' => 'table', 'id' => $cancel->id,
                'request_id' => $requestId, 'qty' => (float) ($cancel->payload['cancelled_qty'] ?? 0)];
            $at = null;
            $legacyStaffId = null;
        } else {
            return null;
        }
        $used = 0.0;
        foreach (SyncEvent::query()->where('device_id', $device->id)->where('event_type', 'product.waste')
            ->where('ack_status', SyncEvent::STATUS_PROCESSED)->whereKeyNot($event->id)->get() as $previous) {
            $proof = $previous->result_json['table_cancellation_waste'] ?? null;
            $old = (array) $previous->payload_json;
            // The cancellation request is the allowance identity; proof source
            // and the staff member recording the loss are audit details only.
            $same = is_array($proof) && ($proof['request_id'] ?? null) === $authority['request_id'];
            // Account for successful bookings made before this consumption stamp.
            if ($proof === null && count($old['lines'] ?? []) === 1
                && (int) ($old['lines'][0]['product_id'] ?? 0) === (int) $line['product_id']) {
                $same = ($old['table_cancellation_request_id'] ?? null) === $authority['request_id']
                    || (! isset($old['table_cancellation_request_id']) && $at !== null
                        && ($old['staff_id'] ?? null) === $legacyStaffId
                        && $previous->client_timestamp?->equalTo($at)
                        && str_starts_with((string) ($old['note'] ?? ''), 'cancelled after preparation — table '));
            }
            if ($same) {
                $used += (float) ($proof['quantity'] ?? $old['lines'][0]['qty'] ?? 0);
            }
        }
        $qty = round((float) $line['qty'], 3);
        if ($qty + $used > $authority['qty'] + 1e-9) {
            throw new RuntimeException('Prepared table waste exceeds the remaining cancellation quantity.');
        }

        return $authority + ['quantity' => $qty, 'company_id' => (int) $device->company_id, 'branch_id' => (int) $device->branch_id];
    }

    /**
     * The per-unit cost frozen at waste time.
     *
     * LAUNCH-P3 fix order 1 K2 — a COOKED piece is valued like its cost of
     * goods (pos_merchant OrderLineCost): the batch cost per piece stamped on
     * the latest 'produced' movement at or before the waste, at this branch,
     * else at any branch. Without a stamped batch (everything produced before
     * P3), and for unit products: cost_price when set, else the recipe cost =
     * Σ(recipe.quantity × ingredient cost) through prep items (the explode
     * rule); a unit product with no recipe falls back to 0.
     */
    private function unitCost(Product $product, int $branchId, CarbonInterface $wastedAt): string
    {
        if ($product->stock_mode === 'cooked') {
            $at = $wastedAt->copy()->utc()->format('Y-m-d H:i:s');
            $batches = DB::table('pos_product_stock_movements')
                ->where('company_id', (int) $product->company_id)
                ->where('product_id', (int) $product->id)
                ->where('movement_type', 'produced')
                ->whereNotNull('unit_cost')
                ->where('occurred_at', '<=', $at)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id');
            $batchCost = (clone $batches)->where('branch_id', $branchId)->value('unit_cost') ?? $batches->value('unit_cost');
            if ($batchCost !== null) {
                return (string) StockDecimal::unitCost(StockDecimal::exact($batchCost));
            }
        }

        $costPrice = (float) ($product->cost_price ?? 0);
        if ($costPrice > 0) {
            return number_format($costPrice, 3, '.', '');
        }

        // LAUNCH-P2 — a frozen per-piece (production) cost keeps 6 decimals.
        return (string) StockDecimal::unitCost(
            // Fix order PK-A1 (M1) — a piece's recipe: one line per ingredient.
            (new PrepExploder((int) $product->company_id))->cost(OrderTypes::widestLinePerItem((new RecipeInForce)->lines((int) $product->id))),
        );
    }
}
