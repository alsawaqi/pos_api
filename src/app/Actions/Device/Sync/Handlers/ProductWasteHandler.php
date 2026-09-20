<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\BranchProduct;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SyncEvent;
use App\Models\TableSessionEvent;
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
 * + a per-unit cost FROZEN at this moment — cost_price when set, else a cooked
 * item's recipe cost) and the branch shelf (pos_branch_product.stock_qty) is
 * decremented. Ordinary waste is capped by shelf stock. A proved prepared-table
 * cancellation may record physical loss even when the shelf is already negative. The merchant Loss/Waste report surfaces it
 * with no extra wiring.
 *
 * Wastage is LOSS-tracking, NOT an expense — the cost was already booked at
 * purchase (unit) or production (cooked) under the cash model.
 *
 * The whole event is atomic: a bad line (unknown/ineligible product, over-waste)
 * fails the entire submission, mirroring the ingredient stock-count flow.
 */
class ProductWasteHandler implements SyncEventHandler
{
    /** Mirror of pos_merchant's App\Enums\WasteReason (no enum exists here). */
    private const REASONS = ['expired', 'spoiled', 'broken', 'dropped', 'contamination', 'other'];

    public function handle(SyncEvent $event, Device $device): array
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
        $wastedAt = isset($payload['wasted_at'])
            ? Carbon::parse((string) $payload['wasted_at'])
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

        $preparedTableWaste = $this->isPreparedTableWaste($event, $device, $payload, $wastedAt);

        return DB::transaction(function () use ($resolved, $companyId, $branchId, $staffId, $note, $wastedAt, $preparedTableWaste): array {
            $wastedLines = 0;
            $totalQty = 0.0;

            foreach ($resolved as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $qty = $line['qty'];

                $row = BranchProduct::query()
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();
                $available = $row?->stock_qty !== null ? (float) $row->stock_qty : 0.0;

                if ($row === null || (! $preparedTableWaste && $qty > $available + 1e-9)) {
                    throw new RuntimeException(sprintf(
                        'Cannot waste %s of %s: only %s on the shelf.',
                        rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                        $product->name,
                        rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                    ));
                }

                ProductStockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'movement_type' => ProductStockMovement::TYPE_WASTE,
                    'reason' => $line['reason'],
                    'quantity' => number_format(-$qty, 3, '.', ''),
                    'unit_cost' => $this->unitCost($product),
                    'recorded_by_pos_staff_id' => $staffId,
                    'note' => $note,
                    'occurred_at' => $wastedAt,
                    'created_at' => now(),
                ]);

                $row->stock_qty = $available - $qty;
                $row->save();

                $wastedLines++;
                $totalQty += $qty;
            }

            return [
                'wasted_lines' => $wastedLines,
                'total_qty' => number_format($totalQty, 3, '.', ''),
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
    private function isPreparedTableWaste(SyncEvent $event, Device $device, array $payload, Carbon $wastedAt): bool
    {
        if (count($payload['lines']) !== 1 || $payload['lines'][0]['reason'] !== 'other') {
            return false;
        }
        $line = $payload['lines'][0];
        $requestId = $payload['table_cancellation_request_id'] ?? null;
        if ($requestId === null && ! str_starts_with((string) ($payload['note'] ?? ''), 'cancelled after preparation — table ')) {
            return false;
        }
        $query = SyncEvent::query()->where('device_id', $device->id)
            ->where('event_type', 'table.session.cancel_line')->where('ack_status', SyncEvent::STATUS_PROCESSED);
        if ($requestId !== null) {
            $query->where('client_event_id', $requestId);
        } else {
            $query->where('client_timestamp', $event->client_timestamp ?? $wastedAt);
        }
        $matches = $query->get()->filter(function (SyncEvent $cancel) use ($line, $payload): bool {
            $p = (array) $cancel->payload_json;

            return ($p['prepared'] ?? false) === true
                && (int) ($p['product_id'] ?? 0) === (int) $line['product_id']
                && (float) ($p['qty'] ?? 0) >= (float) $line['qty']
                && ($p['staff_id'] ?? null) === ($payload['staff_id'] ?? null)
                && in_array($cancel->result_json['outcome'] ?? null, ['cancelled', 'replayed', 'bill_terminal', 'nothing_to_cancel'], true);
        });
        if ($matches->count() === 1) {
            return true;
        }
        if ($requestId === null || $matches->isNotEmpty()) {
            return false;
        }

        // Online shared-table cancellations use the existing lifecycle journal.
        return TableSessionEvent::query()->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->where('device_id', $device->id)
            ->where('event_type', 'round_resolved')->where('payload->action', 'line_cancelled')
            ->where('payload->client_request_id', $requestId)->get()->contains(function (TableSessionEvent $cancel) use ($line): bool {
                $p = (array) $cancel->payload;

                return ($p['prepared'] ?? false) === true
                    && (int) ($p['product_id'] ?? 0) === (int) $line['product_id']
                    && (float) ($p['cancelled_qty'] ?? 0) >= (float) $line['qty'];
            });
    }

    /**
     * The per-unit cost frozen at waste time: cost_price when set, else (for a
     * cooked item) its recipe cost = Σ(recipe.quantity × ingredient cost). A
     * unit product with no recipe falls back to 0.
     */
    private function unitCost(Product $product): string
    {
        $costPrice = (float) ($product->cost_price ?? 0);
        if ($costPrice > 0) {
            return number_format($costPrice, 3, '.', '');
        }

        $recipeCost = (float) (DB::table('pos_product_recipes as r')
            ->join('pos_ingredients as i', 'i.id', '=', 'r.ingredient_id')
            ->where('r.product_id', $product->id)
            ->selectRaw('COALESCE(SUM(r.quantity * COALESCE(i.default_unit_cost, 0)), 0) AS c')
            ->value('c') ?? 0);

        return number_format($recipeCost, 3, '.', '');
    }
}
