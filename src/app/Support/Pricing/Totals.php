<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use LogicException;

final class Totals
{
    public static function priceOrder(PricingInput $input): PriceResult
    {
        $lines = $input->lines;
        $delivery = $input->isDeliveryProvider;
        $raw = LineTotals::rawSubtotalBaisas($lines);

        $lineDiscounts = Discounts::lineDiscountsFor(
            $lines,
            $input->discountRules,
            $input->now,
            $input->branchId,
            $delivery,
        );
        $lineDiscountByIndex = [];
        foreach ($lineDiscounts as $discount) {
            $lineDiscountByIndex[$discount->lineIndex] = $discount->amountBaisas;
        }
        $lineDiscountTotal = array_reduce(
            $lineDiscounts,
            static fn (int $sum, LineDiscountResult $discount): int => $sum + $discount->amountBaisas,
            0,
        );

        $lineNets = [];
        foreach ($lines as $index => $line) {
            $lineNets[] = LineTotals::lineNetBaisas($line, $lineDiscountByIndex[$index] ?? 0);
        }

        $appliedOffers = $delivery || $input->branchId === null || $lines === []
            ? []
            : OfferEngine::evaluateOffers(
                $lines,
                $lineNets,
                array_values(array_filter(
                    $input->offers,
                    static fn (OfferSpec $offer): bool => $offer->autoApply || $offer->isBundle(),
                )),
                $input->now,
                $input->branchId,
            );
        $offerDiscountTotal = array_reduce(
            $appliedOffers,
            static fn (int $sum, AppliedOfferResult $offer): int => $sum + $offer->totalBaisas(),
            0,
        );

        $orderDiscount = Discounts::orderDiscountBaisasFor($input->orderDiscount, $raw);
        $discountTotal = min(max($orderDiscount + $lineDiscountTotal + $offerDiscountTotal, 0), $raw);
        $subtotal = $raw - $discountTotal;

        $giftAmounts = Comps::giftAmountsBaisasFor($lines, $lineDiscountByIndex);
        $giftedTotalRaw = array_sum($giftAmounts);
        $compTotal = Comps::compTotalBaisasFor(
            $lines,
            $lineDiscountByIndex,
            $giftedTotalRaw,
            $subtotal,
            $input->comp,
        );
        $managerComp = Comps::managerCompBaisasFor($compTotal, $giftedTotalRaw, $subtotal);
        $taxedBase = min(max($subtotal - $compTotal, 0), $subtotal);

        // LAUNCH-P4 — inclusive prices take each tax OUT of the taxed gross
        // base (grand = that base); exclusive ones add it on top. Delivery
        // orders carry no tax either way.
        $inclusive = $input->pricesIncludeTax;
        $taxLines = $delivery ? [] : ($inclusive
            ? Taxes::inclusiveTaxLinesBaisasFor($taxedBase, $input->taxes)
            : Taxes::taxLinesBaisasFor($taxedBase, $input->taxes));
        $taxTotal = array_reduce(
            $taxLines,
            static fn (int $sum, TaxLineResult $line): int => $sum + $line->amountBaisas,
            0,
        );
        $grand = $inclusive ? $taxedBase : $taxedBase + $taxTotal;

        if ($raw - $discountTotal - $compTotal + ($inclusive ? 0 : $taxTotal) !== $grand) {
            throw new LogicException('pricing invariant violated');
        }

        return new PriceResult(
            rawSubtotalBaisas: $raw,
            lineDiscounts: $lineDiscounts,
            lineDiscountTotalBaisas: $lineDiscountTotal,
            appliedOffers: $appliedOffers,
            offerDiscountTotalBaisas: $offerDiscountTotal,
            orderDiscountBaisas: $orderDiscount,
            discountTotalBaisas: $discountTotal,
            subtotalBaisas: $subtotal,
            giftAmountsBaisas: $giftAmounts,
            giftedTotalBaisas: $giftedTotalRaw,
            managerCompBaisas: $managerComp,
            compTotalBaisas: $compTotal,
            taxedBaseBaisas: $taxedBase,
            taxLines: $taxLines,
            taxTotalBaisas: $taxTotal,
            grandTotalBaisas: $grand,
            pricesIncludeTax: $inclusive,
        );
    }
}
