<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Support\Pricing\PriceResult;

/** Builds the immutable, customer-visible line snapshot for one priced round. */
final class FreezeQrRoundLinesAction
{
    /** @return list<array<string, mixed>> */
    public function handle(QrPricingLoadResult $loaded, PriceResult $price): array
    {
        $discounts = [];
        foreach ($price->lineDiscounts as $discount) {
            $discounts[$discount->lineIndex] = ($discounts[$discount->lineIndex] ?? 0)
                + $discount->amountBaisas;
        }
        foreach ($price->appliedOffers as $offer) {
            foreach ($offer->lineAmountsBaisas as $lineIndex => $amount) {
                $discounts[(int) $lineIndex] = ($discounts[(int) $lineIndex] ?? 0) + $amount;
            }
        }

        return array_map(
            static fn (QrResolvedLine $line, int $index): array => [
                'product_id' => (int) $line->product->id,
                'product_name' => (string) $line->product->name,
                'product_name_ar' => $line->product->name_ar,
                'qty' => $line->qty,
                'notes' => $line->notes,
                'base_price_baisas' => $line->basePriceBaisas,
                'unit_price_baisas' => $line->unitPriceBaisas,
                'line_discount_baisas' => (int) ($discounts[$index] ?? 0),
                'line_total_baisas' => $line->unitPriceBaisas * $line->qty,
                'addons' => array_map(
                    static fn (QrResolvedAddOn $addon): array => [
                        'add_on_id' => (int) $addon->addon->id,
                        'name' => (string) $addon->addon->name,
                        'name_ar' => $addon->addon->name_ar,
                        'price_delta_baisas' => $addon->priceDeltaBaisas,
                    ],
                    $line->addons,
                ),
            ],
            $loaded->resolvedLines,
            array_keys($loaded->resolvedLines),
        );
    }
}
