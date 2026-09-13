<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\PaymentReversal;
use Illuminate\Support\Facades\DB;

final class ReturnRefundedUnitStockAction
{
    public function handle(PaymentReversal $reversal): void
    {
        $lines = DB::table('pos_payment_reversal_lines')->where('reversal_id', $reversal->id)
            ->where('stock_mode_at_refund', 'unit')->where('returned_to_stock', false)
            ->orderBy('order_item_id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $item = DB::table('pos_order_items')->where('id', $line->order_item_id)->where('order_id', $reversal->order_id)->first();
            if ($item === null || $item->product_id === null) {
                continue;
            }
            $affected = DB::table('pos_branch_product')->where('branch_id', $reversal->branch_id)
                ->where('product_id', $item->product_id)->whereNotNull('stock_qty')
                ->increment('stock_qty', $line->qty, ['updated_at' => now()]);
            if ($affected === 0) {
                continue;
            }
            $movement = DB::table('pos_product_stock_movements')->insertGetId([
                'company_id' => $reversal->company_id, 'branch_id' => $reversal->branch_id,
                'product_id' => $item->product_id, 'movement_type' => 'refund_return', 'quantity' => $line->qty,
                'reference_type' => 'pos_payment_reversals', 'reference_id' => $reversal->id,
                'recorded_by_pos_staff_id' => $reversal->approved_by_staff_id,
                'note' => 'Refund '.$reversal->uuid, 'occurred_at' => now(), 'created_at' => now(),
            ]);
            DB::table('pos_payment_reversal_lines')->where('id', $line->id)->update([
                'returned_to_stock' => true, 'product_stock_movement_id' => $movement,
            ]);
        }
    }
}
