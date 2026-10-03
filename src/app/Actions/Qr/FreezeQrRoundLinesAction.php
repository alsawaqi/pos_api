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
            static fn (QrResolvedLine $line, int $index): array => self::frozen($line, $discounts[$index] ?? 0),
            $loaded->resolvedLines,
            array_keys($loaded->resolvedLines),
        );
    }

    /**
     * LAUNCH-P4 — a combo line also freezes its `components`, per ONE combo,
     * for the kitchen ticket and the bill: {slot_id, slot_name, slot_name_ar,
     * product_id, product_name, product_name_ar, qty, extra_price_baisas,
     * notes, addons}. A standard line keeps its exact earlier shape.
     *
     * @return array<string, mixed>
     */
    private static function frozen(QrResolvedLine $line, int $discount): array
    {
        $frozen = self::line($line, $discount);
        if ($line->isCombo()) {
            $frozen['components'] = array_map(static fn (QrResolvedComponent $component): array => [
                'slot_id' => $component->slotId,
                'slot_name' => $component->slotName,
                'slot_name_ar' => $component->slotNameAr,
                'product_id' => (int) $component->product->id,
                'name' => (string) $component->product->name,
                'name_ar' => $component->product->name_ar,
                'product_name' => (string) $component->product->name,
                'product_name_ar' => $component->product->name_ar,
                'qty' => $component->qty,
                'extra_price_baisas' => $component->extraPriceBaisas,
                'notes' => $component->notes,
                'addons' => self::addons($component->addons),
            ], $line->components);
        }

        return $frozen;
    }

    /**
     * @param  list<QrResolvedAddOn>  $addons
     * @return list<array<string, mixed>>
     */
    private static function addons(array $addons): array
    {
        return array_map(
            static fn (QrResolvedAddOn $addon): array => [
                'add_on_id' => (int) $addon->addon->id,
                'name' => (string) $addon->addon->name,
                'name_ar' => $addon->addon->name_ar,
                'price_delta_baisas' => $addon->priceDeltaBaisas,
            ],
            $addons,
        );
    }

    /** @return array<string, mixed> */
    private static function line(QrResolvedLine $line, int $discount): array
    {
        return [
            'product_id' => (int) $line->product->id,
            'product_name' => (string) $line->product->name,
            'product_name_ar' => $line->product->name_ar,
            'qty' => $line->qty,
            'notes' => $line->notes,
            'base_price_baisas' => $line->basePriceBaisas,
            'unit_price_baisas' => $line->unitPriceBaisas,
            'line_discount_baisas' => $discount,
            'line_total_baisas' => $line->unitPriceBaisas * $line->qty,
            'addons' => self::addons($line->addons),
        ];
    }
}
