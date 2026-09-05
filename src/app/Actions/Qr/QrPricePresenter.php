<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Support\Money;
use App\Support\Pricing\PriceResult;

/** Public money views contain order facts only, never customer facts. */
final class QrPricePresenter
{
    /** @return array<string, mixed> */
    public function quote(QrPricingLoadResult $loaded, PriceResult $price): array
    {
        $lineDiscounts = [];
        foreach ($price->lineDiscounts as $discount) {
            $lineDiscounts[$discount->lineIndex] = ($lineDiscounts[$discount->lineIndex] ?? 0)
                + $discount->amountBaisas;
        }
        foreach ($price->appliedOffers as $offer) {
            foreach ($offer->lineAmountsBaisas as $lineIndex => $amount) {
                $lineDiscounts[(int) $lineIndex] = ($lineDiscounts[(int) $lineIndex] ?? 0) + $amount;
            }
        }

        return [
            'lines' => array_map(static fn (QrResolvedLine $line, int $index): array => [
                'product_id' => (int) $line->product->id,
                'qty' => $line->qty,
                'addon_ids' => $line->addonIds(),
                'notes' => $line->notes,
                'base_price_baisas' => $line->basePriceBaisas,
                'addon_total_baisas' => $line->unitPriceBaisas - $line->basePriceBaisas,
                'unit_price_baisas' => $line->unitPriceBaisas,
                'line_discount_baisas' => $lineDiscounts[$index] ?? 0,
                'line_total_baisas' => $line->unitPriceBaisas * $line->qty,
            ], $loaded->resolvedLines, array_keys($loaded->resolvedLines)),
            'subtotal_baisas' => $price->rawSubtotalBaisas,
            'discount_total_baisas' => $price->discountTotalBaisas,
            'tax_total_baisas' => $price->taxTotalBaisas,
            'grand_total_baisas' => $price->grandTotalBaisas,
        ];
    }

    /** @return array<string, mixed> */
    public function checkout(Order $order): array
    {
        return [
            'order' => [
                'uuid' => $order->uuid,
                'status' => $order->status,
                'receipt_number' => $order->receipt_number,
                'temp_reference' => $order->temp_reference,
                'subtotal_baisas' => Money::toBaisas($order->subtotal),
                'discount_total_baisas' => Money::toBaisas($order->discount_total),
                'tax_total_baisas' => Money::toBaisas($order->tax_total),
                'grand_total_baisas' => Money::toBaisas($order->grand_total),
            ],
        ];
    }
}
