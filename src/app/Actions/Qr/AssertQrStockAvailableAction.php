<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Admission only: inventory still moves exactly once, at payment. */
final class AssertQrStockAvailableAction
{
    /** @param list<QrResolvedLine> $lines */
    public function handle(int $companyId, int $branchId, array $lines): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('QR stock admission requires the order transaction.');
        }

        $requested = [];
        foreach ($lines as $line) {
            if (in_array($line->product->stock_mode, ['unit', 'cooked'], true)) {
                $id = (int) $line->product->id;
                $requested[$id] = ($requested[$id] ?? 0) + $line->qty * 1000;
            }
        }
        if ($requested === []) {
            return;
        }

        // One stable lock order for competing QR submissions and the atomic
        // pay-time balance update. Hold these locks until the writer commits.
        $stock = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', array_keys($requested))
            ->whereNotNull('stock_qty')->orderBy('product_id')->lockForUpdate()->get();
        if ($stock->isEmpty()) {
            return; // NULL/missing branch stock retains its untracked meaning.
        }

        $unpaid = DB::table('pos_orders')->select('id')
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_HELD,
                Order::STATUS_KITCHEN, Order::STATUS_AWAITING_PAYMENT]);

        $items = DB::table('pos_order_items')->whereIn('order_id', $unpaid)
            ->where('status', OrderItem::STATUS_OPEN)
            ->whereIn('product_id', $stock->pluck('product_id'))
            ->selectRaw('product_id, qty, NULL as priced_lines');
        $pending = DB::table('pos_qr_order_rounds')->whereIn('order_id', $unpaid)
            ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->selectRaw('NULL as product_id, NULL as qty, priced_lines');

        // A single SQL statement sees one snapshot: concurrent confirmation
        // cannot disappear between the pending-round and accepted-item reads.
        // Accepted rounds are counted via items ONLY; rejected/held lines do
        // not reserve stock. No reservation table or inventory write is needed.
        $committed = [];
        foreach ($items->unionAll($pending)->get() as $row) {
            $quantities = $row->priced_lines === null
                ? [['product_id' => $row->product_id, 'qty' => $row->qty]]
                : json_decode($row->priced_lines, true, 512, JSON_THROW_ON_ERROR);
            foreach ($quantities as $line) {
                $id = (int) ($line['product_id'] ?? 0);
                if (isset($requested[$id]) && ! isset($line['held_reason'])) {
                    $committed[$id] = ($committed[$id] ?? 0)
                        + (int) round((float) $line['qty'] * 1000);
                }
            }
        }

        foreach ($stock as $balance) {
            $id = (int) $balance->product_id;
            $available = (int) round((float) $balance->stock_qty * 1000) - ($committed[$id] ?? 0);
            if ($requested[$id] > $available) {
                throw new QrCatalogueException(
                    QrCatalogueException::PRODUCT_UNAVAILABLE,
                    'The requested quantity is no longer available. Please reduce the quantity or choose another item.',
                );
            }
        }
    }
}
