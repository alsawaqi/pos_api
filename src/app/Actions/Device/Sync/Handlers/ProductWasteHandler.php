<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\BranchProduct;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SyncEvent;
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
 * decremented. Waste can never drive a shelf negative (the row is locked and the
 * removed quantity is capped by it). The merchant Loss/Waste report surfaces it
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
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid product.waste payload: '.implode('; ', $validator->errors()->all()));
        }

        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;
        $wastedAt = isset($payload['wasted_at'])
            ? Carbon::parse((string) $payload['wasted_at'])
            : ($event->client_timestamp ?? now());
        // The acting staff id is recorded as sent by the device (the device's
        // authenticated session is the trust boundary), matching the stock.count
        // flow — we don't re-verify it here.
        $staffId = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
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

        return DB::transaction(function () use ($resolved, $companyId, $branchId, $staffId, $note, $wastedAt): array {
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

                if ($qty > $available + 1e-9) {
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
