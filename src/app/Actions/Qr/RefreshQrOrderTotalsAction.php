<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Support\Money;

/** Recomputes a dine-in order header from accepted frozen rounds only. */
final class RefreshQrOrderTotalsAction
{
    public function handle(Order $order): void
    {
        $totals = QrOrderRound::query()
            ->where('order_id', $order->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->selectRaw(
                'COALESCE(SUM(subtotal_baisas), 0) AS subtotal_baisas, '.
                'COALESCE(SUM(tax_baisas), 0) AS tax_baisas, '.
                'COALESCE(SUM(total_baisas), 0) AS total_baisas',
            )
            ->first();

        $subtotal = (int) $totals->subtotal_baisas;
        $tax = (int) $totals->tax_baisas;
        $total = (int) $totals->total_baisas;
        $discount = max(0, $subtotal + $tax - $total);

        $order->update([
            'subtotal' => Money::toOmr($subtotal),
            'discount_total' => Money::toOmr($discount),
            'tax_total' => Money::toOmr($tax),
            'grand_total' => Money::toOmr($total),
        ]);
    }
}
