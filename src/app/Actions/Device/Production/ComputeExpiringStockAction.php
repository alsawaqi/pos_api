<?php

declare(strict_types=1);

namespace App\Actions\Device\Production;

use App\Models\Device;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P-G1.5 — which cooked pieces at the device's branch are EXPIRED (or
 * expire today), via FIFO "virtual lots" — no lot tables:
 *
 *   - every POSITIVE product-ledger row at the branch is a lot, dated by
 *     occurred_at. A 'produced' lot carries ITS batch's chef-set
 *     expires_at (pos_productions via reference_id); any other inflow
 *     (receive / allocate / transfer / adjustment-up) falls back to
 *     occurred_at + the product's shelf_life_days (no shelf life = the
 *     lot never expires);
 *   - the sum of all NEGATIVE rows depletes the lots oldest-first
 *     (kitchens sell the older tray first — the FIFO assumption);
 *   - stock the ledger can't explain (e.g. a balance seeded directly from
 *     the merchant product form before any ledger row existed) is treated
 *     as an UNDATED lot that never expires — we never force a disposition
 *     on pieces we can't honestly age;
 *   - if the ledger explains MORE than the actual balance, the surplus
 *     depletes FIFO too, so remaining lots always sum to the real count.
 *
 * "Expired" uses an end-of-today cutoff: the disposition runs at day end,
 * so a batch expiring tonight is tonight's decision.
 */
final readonly class ComputeExpiringStockAction
{
    /**
     * @return list<array{
     *     product_id: int, uuid: string, name: string, name_ar: string|null,
     *     branch_stock_qty: float, expired_qty: float,
     *     lots: list<array{quantity: float, expires_at: string|null}>
     * }>
     */
    public function handle(Device $device): array
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;
        $cutoff = now()->endOfDay();

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('stock_mode', 'cooked')
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return [];
        }

        $balances = DB::table('pos_branch_product')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $products->keys()->all())
            ->whereNotNull('stock_qty')
            ->where('stock_qty', '>', 0)
            ->pluck('stock_qty', 'product_id');

        if ($balances->isEmpty()) {
            return [];
        }

        $movements = DB::table('pos_product_stock_movements')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $balances->keys()->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['product_id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'occurred_at'])
            ->groupBy('product_id');

        // Batch expiries for 'produced' lots, in one query.
        $productionIds = [];
        foreach ($movements as $rows) {
            foreach ($rows as $row) {
                if ($row->movement_type === 'produced' && $row->reference_type === 'pos_productions' && $row->reference_id !== null) {
                    $productionIds[] = (int) $row->reference_id;
                }
            }
        }
        $batchExpiries = $productionIds === []
            ? collect()
            : DB::table('pos_productions')->whereIn('id', $productionIds)->pluck('expires_at', 'id');

        $out = [];
        foreach ($balances as $productId => $balance) {
            $product = $products->get((int) $productId);
            $balance = (float) $balance;
            $rows = $movements->get($productId) ?? collect();

            // Build the dated lots + the total recorded depletion.
            $lots = [];
            $depletion = 0.0;
            foreach ($rows as $row) {
                $qty = (float) $row->quantity;
                if ($qty > 0) {
                    $lots[] = [
                        'quantity' => $qty,
                        'expires_at' => $this->lotExpiry($row, $product, $batchExpiries),
                        'occurred_at' => (string) $row->occurred_at,
                    ];
                } elseif ($qty < 0) {
                    $depletion += -$qty;
                }
            }

            // Unexplained balance = an undated, never-expiring lot FIRST in
            // the FIFO order (it predates everything the ledger knows).
            $lotTotal = array_sum(array_column($lots, 'quantity'));
            $unexplained = $balance + $depletion - $lotTotal;
            if ($unexplained > 0.0005) {
                array_unshift($lots, [
                    'quantity' => $unexplained,
                    'expires_at' => null,
                    'occurred_at' => '',
                ]);
            } elseif ($unexplained < -0.0005) {
                // Ledger explains MORE than reality: extra unrecorded
                // depletion — drain it FIFO along with the recorded one.
                $depletion += -$unexplained;
            }

            // FIFO depletion: oldest lots drain first.
            $remaining = [];
            foreach ($lots as $lot) {
                if ($depletion >= $lot['quantity'] - 0.0005) {
                    $depletion -= $lot['quantity'];

                    continue;
                }
                $left = $lot['quantity'] - $depletion;
                $depletion = 0.0;
                $remaining[] = ['quantity' => $left, 'expires_at' => $lot['expires_at']];
            }

            $expired = 0.0;
            foreach ($remaining as $lot) {
                if ($lot['expires_at'] !== null && Carbon::parse($lot['expires_at'])->lte($cutoff)) {
                    $expired += $lot['quantity'];
                }
            }

            if ($expired <= 0.0005) {
                continue;
            }

            $out[] = [
                'product_id' => (int) $productId,
                'uuid' => (string) $product->uuid,
                'name' => (string) $product->name,
                'name_ar' => $product->name_ar,
                'branch_stock_qty' => $balance,
                'expired_qty' => round($expired, 3),
                'lots' => array_map(static fn (array $lot): array => [
                    'quantity' => round($lot['quantity'], 3),
                    'expires_at' => $lot['expires_at'],
                ], $remaining),
            ];
        }

        return $out;
    }

    /**
     * A lot's expiry: produced lots use their batch's chef-set expires_at;
     * every other inflow falls back to occurred_at + shelf_life_days.
     * NULL = the lot never expires.
     *
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $batchExpiries
     */
    private function lotExpiry(object $row, Product $product, $batchExpiries): ?string
    {
        if ($row->movement_type === 'produced' && $row->reference_type === 'pos_productions' && $row->reference_id !== null) {
            $expiry = $batchExpiries->get((int) $row->reference_id);

            return $expiry !== null ? Carbon::parse((string) $expiry)->toIso8601String() : null;
        }

        if ($product->shelf_life_days === null) {
            return null;
        }

        return Carbon::parse((string) $row->occurred_at)
            ->addDays((int) $product->shelf_life_days)
            ->endOfDay()
            ->toIso8601String();
    }
}
