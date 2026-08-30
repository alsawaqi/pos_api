<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Pricing\AppliedOfferResult;
use App\Support\Pricing\LineDiscountResult;
use App\Support\Pricing\PriceResult;
use Carbon\CarbonInterface;

/** Appends one already-priced round to order children without touching prior rows. */
final class AppendQrPricedLinesAction
{
    public function __construct(private readonly OrderLineSnapshotter $snapshots) {}

    public function handle(
        Order $order,
        QrSession $session,
        QrPricingLoadResult $loaded,
        PriceResult $price,
        CarbonInterface $appliedAt,
    ): void {
        $itemIds = [];
        foreach ($loaded->resolvedLines as $index => $resolved) {
            $productSnapshots = $this->snapshots->product($resolved->product);
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $resolved->product->id,
                'product_name_snapshot' => $resolved->product->name,
                'qty' => $resolved->qty,
                'unit_price_snapshot' => Money::toOmr($resolved->unitPriceBaisas),
                'line_discount' => Money::toOmr($this->lineDiscountBaisas($price, $index)),
                'line_total' => Money::toOmr($resolved->unitPriceBaisas * $resolved->qty),
                'recipe_snapshot_json' => $productSnapshots['recipe_snapshot_json'],
                'component_snapshot_json' => $productSnapshots['component_snapshot_json'],
                'status' => OrderItem::STATUS_OPEN,
                'notes' => $resolved->notes !== '' ? $resolved->notes : null,
            ]);
            $itemIds[$index] = (int) $item->id;

            foreach ($resolved->addons as $resolvedAddon) {
                $addonSnapshots = $this->snapshots->addon(
                    $resolvedAddon->addon,
                    (int) $session->company_id,
                );
                OrderItemAddon::query()->create([
                    'order_item_id' => $item->id,
                    'add_on_id' => $resolvedAddon->addon->id,
                    'add_on_name_snapshot' => $resolvedAddon->addon->name,
                    'price_delta_snapshot' => Money::toOmr($resolvedAddon->priceDeltaBaisas),
                ] + $addonSnapshots);
            }
        }

        $this->writeDiscountRows($order, $loaded, $price, $itemIds, $appliedAt);
    }

    private function lineDiscountBaisas(PriceResult $price, int $lineIndex): int
    {
        $amount = 0;
        foreach ($price->lineDiscounts as $discount) {
            if ($discount->lineIndex === $lineIndex) {
                $amount += $discount->amountBaisas;
            }
        }
        foreach ($price->appliedOffers as $offer) {
            $amount += (int) ($offer->lineAmountsBaisas[$lineIndex] ?? 0);
        }

        return $amount;
    }

    /** @param array<int, int> $itemIds */
    private function writeDiscountRows(
        Order $order,
        QrPricingLoadResult $loaded,
        PriceResult $price,
        array $itemIds,
        CarbonInterface $appliedAt,
    ): void {
        foreach ($price->lineDiscounts as $discount) {
            $this->writeLineDiscount($order, $discount, $itemIds, $appliedAt);
        }

        $orderDiscountBaisas = $price->orderDiscountRowBaisas();
        if ($orderDiscountBaisas > 0 && $loaded->autoOrderDiscount !== null) {
            OrderDiscount::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'order_item_id' => null,
                'discount_id' => $loaded->autoOrderDiscount->id,
                'offer_id' => null,
                'name_snapshot' => $loaded->autoOrderDiscount->name,
                'amount_type_snapshot' => $loaded->autoOrderDiscount->amountType,
                'amount' => Money::toOmr($orderDiscountBaisas),
                'applied_at' => $appliedAt,
            ]);
        }

        $remainingOfferBaisas = max(
            0,
            $price->discountTotalBaisas
                - $price->lineDiscountTotalBaisas
                - $orderDiscountBaisas,
        );
        foreach ($price->appliedOffers as $offer) {
            $remainingOfferBaisas = $this->writeOfferDiscounts(
                $order,
                $offer,
                $itemIds,
                $appliedAt,
                $remainingOfferBaisas,
            );
        }
    }

    /** @param array<int, int> $itemIds */
    private function writeLineDiscount(
        Order $order,
        LineDiscountResult $discount,
        array $itemIds,
        CarbonInterface $appliedAt,
    ): void {
        OrderDiscount::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'order_item_id' => $itemIds[$discount->lineIndex] ?? null,
            'discount_id' => $discount->ruleId,
            'offer_id' => null,
            'name_snapshot' => $discount->label,
            'amount_type_snapshot' => $discount->amountType,
            'amount' => Money::toOmr($discount->amountBaisas),
            'applied_at' => $appliedAt,
        ]);
    }

    /** @param array<int, int> $itemIds */
    private function writeOfferDiscounts(
        Order $order,
        AppliedOfferResult $offer,
        array $itemIds,
        CarbonInterface $appliedAt,
        int $remainingBaisas,
    ): int {
        foreach ($offer->lineAmountsBaisas as $lineIndex => $amount) {
            $amount = min(max(0, $amount), $remainingBaisas);
            if ($amount <= 0) {
                continue;
            }
            $this->writeOfferDiscount(
                $order,
                $offer,
                $amount,
                $itemIds[(int) $lineIndex] ?? null,
                $appliedAt,
            );
            $remainingBaisas -= $amount;
        }

        $orderAmount = min(max(0, $offer->orderAmountBaisas), $remainingBaisas);
        if ($orderAmount > 0) {
            $this->writeOfferDiscount($order, $offer, $orderAmount, null, $appliedAt);
            $remainingBaisas -= $orderAmount;
        }

        return $remainingBaisas;
    }

    private function writeOfferDiscount(
        Order $order,
        AppliedOfferResult $offer,
        int $amount,
        ?int $orderItemId,
        CarbonInterface $appliedAt,
    ): void {
        OrderDiscount::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'order_item_id' => $orderItemId,
            'discount_id' => null,
            'offer_id' => $offer->offerId,
            'name_snapshot' => $offer->name,
            'amount_type_snapshot' => 'offer',
            'amount' => Money::toOmr($amount),
            'applied_at' => $appliedAt,
        ]);
    }
}
