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
use RuntimeException;

/** Appends one already-priced round to order children without touching prior rows. */
final class AppendQrPricedLinesAction
{
    public function __construct(private readonly OrderLineSnapshotter $snapshots) {}

    /** @return array<int, int> */
    public function handle(
        Order $order,
        QrSession $session,
        QrPricingLoadResult $loaded,
        PriceResult $price,
        CarbonInterface $appliedAt,
    ): array {
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

        return $itemIds;
    }

    /**
     * Freeze every private child-row field needed to append this priced round
     * later without resolving catalogue, inventory, discounts, or offers again.
     *
     * @return array{
     *   version: 1,
     *   items: list<array{attributes: array<string, mixed>, addons: list<array<string, mixed>>}>,
     *   discounts: list<array<string, mixed>>
     * }
     */
    public function buildPayload(
        QrSession $session,
        QrPricingLoadResult $loaded,
        PriceResult $price,
        CarbonInterface $appliedAt,
    ): array {
        $items = [];
        foreach ($loaded->resolvedLines as $index => $resolved) {
            $productSnapshots = $this->snapshots->product($resolved->product);
            $addons = [];
            foreach ($resolved->addons as $resolvedAddon) {
                $addons[] = [
                    'add_on_id' => (int) $resolvedAddon->addon->id,
                    'add_on_name_snapshot' => (string) $resolvedAddon->addon->name,
                    'price_delta_snapshot' => Money::toOmr($resolvedAddon->priceDeltaBaisas),
                ] + $this->snapshots->addon(
                    $resolvedAddon->addon,
                    (int) $session->company_id,
                );
            }

            $items[] = [
                'attributes' => [
                    'product_id' => (int) $resolved->product->id,
                    'product_name_snapshot' => (string) $resolved->product->name,
                    'qty' => $resolved->qty,
                    'unit_price_snapshot' => Money::toOmr($resolved->unitPriceBaisas),
                    'line_discount' => Money::toOmr($this->lineDiscountBaisas($price, $index)),
                    'line_total' => Money::toOmr($resolved->unitPriceBaisas * $resolved->qty),
                    'recipe_snapshot_json' => $productSnapshots['recipe_snapshot_json'],
                    'component_snapshot_json' => $productSnapshots['component_snapshot_json'],
                    'status' => OrderItem::STATUS_OPEN,
                    'notes' => $resolved->notes !== '' ? $resolved->notes : null,
                ],
                'addons' => $addons,
            ];
        }

        $discounts = [];
        $appliedAtValue = $appliedAt->toDateTimeString();
        foreach ($price->lineDiscounts as $discount) {
            $discounts[] = [
                'source' => 'line',
                'order_item_index' => $discount->lineIndex,
                'discount_id' => $discount->ruleId,
                'offer_id' => null,
                'name_snapshot' => $discount->label,
                'amount_type_snapshot' => $discount->amountType,
                'amount' => Money::toOmr($discount->amountBaisas),
                'applied_at' => $appliedAtValue,
            ];
        }

        $orderDiscountBaisas = $price->orderDiscountRowBaisas();
        if ($orderDiscountBaisas > 0 && $loaded->autoOrderDiscount !== null) {
            $discounts[] = [
                'source' => 'order',
                'order_item_index' => null,
                'discount_id' => (int) $loaded->autoOrderDiscount->id,
                'offer_id' => null,
                'name_snapshot' => (string) $loaded->autoOrderDiscount->name,
                'amount_type_snapshot' => (string) $loaded->autoOrderDiscount->amountType,
                'amount' => Money::toOmr($orderDiscountBaisas),
                'applied_at' => $appliedAtValue,
            ];
        }

        $remainingOfferBaisas = max(
            0,
            $price->discountTotalBaisas
                - $price->lineDiscountTotalBaisas
                - $orderDiscountBaisas,
        );
        foreach ($price->appliedOffers as $offer) {
            foreach ($offer->lineAmountsBaisas as $lineIndex => $amount) {
                $amount = min(max(0, $amount), $remainingOfferBaisas);
                if ($amount <= 0) {
                    continue;
                }
                $discounts[] = [
                    'source' => 'offer_line',
                    'order_item_index' => (int) $lineIndex,
                    'discount_id' => null,
                    'offer_id' => $offer->offerId,
                    'name_snapshot' => $offer->name,
                    'amount_type_snapshot' => 'offer',
                    'amount' => Money::toOmr($amount),
                    'applied_at' => $appliedAtValue,
                ];
                $remainingOfferBaisas -= $amount;
            }

            $orderAmount = min(max(0, $offer->orderAmountBaisas), $remainingOfferBaisas);
            if ($orderAmount > 0) {
                $discounts[] = [
                    'source' => 'offer_order',
                    'order_item_index' => null,
                    'discount_id' => null,
                    'offer_id' => $offer->offerId,
                    'name_snapshot' => $offer->name,
                    'amount_type_snapshot' => 'offer',
                    'amount' => Money::toOmr($orderAmount),
                    'applied_at' => $appliedAtValue,
                ];
                $remainingOfferBaisas -= $orderAmount;
            }
        }

        return [
            'version' => 1,
            'items' => $items,
            'discounts' => $discounts,
        ];
    }

    /**
     * Append only the server-authored private payload frozen at submission.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    public function handleStored(Order $order, array $payload): array
    {
        if (($payload['version'] ?? null) !== 1
            || ! is_array($payload['items'] ?? null)
            || ! is_array($payload['discounts'] ?? null)) {
            throw new RuntimeException('Invalid QR round confirmation payload.');
        }

        $itemIds = [];
        foreach ($payload['items'] as $index => $storedItem) {
            if (! is_array($storedItem)
                || ! is_array($storedItem['attributes'] ?? null)
                || ! is_array($storedItem['addons'] ?? null)) {
                throw new RuntimeException('Invalid QR round confirmation item payload.');
            }
            $attributes = $storedItem['attributes'];
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $attributes['product_id'],
                'product_name_snapshot' => $attributes['product_name_snapshot'],
                'qty' => $attributes['qty'],
                'unit_price_snapshot' => $attributes['unit_price_snapshot'],
                'line_discount' => $attributes['line_discount'],
                'line_total' => $attributes['line_total'],
                'recipe_snapshot_json' => $attributes['recipe_snapshot_json'],
                'component_snapshot_json' => $attributes['component_snapshot_json'],
                'status' => $attributes['status'],
                'notes' => $attributes['notes'],
            ]);
            $itemIds[(int) $index] = (int) $item->id;

            foreach ($storedItem['addons'] as $storedAddon) {
                if (! is_array($storedAddon)) {
                    throw new RuntimeException('Invalid QR round confirmation add-on payload.');
                }
                OrderItemAddon::query()->create([
                    'order_item_id' => $item->id,
                    'add_on_id' => $storedAddon['add_on_id'],
                    'add_on_name_snapshot' => $storedAddon['add_on_name_snapshot'],
                    'price_delta_snapshot' => $storedAddon['price_delta_snapshot'],
                    'ingredient_snapshot_json' => $storedAddon['ingredient_snapshot_json'],
                    'linked_product_id' => $storedAddon['linked_product_id'],
                    'product_snapshot_json' => $storedAddon['product_snapshot_json'],
                    'consumption_snapshot_json' => $storedAddon['consumption_snapshot_json'],
                ]);
            }
        }

        foreach ($payload['discounts'] as $storedDiscount) {
            if (! is_array($storedDiscount)) {
                throw new RuntimeException('Invalid QR round confirmation discount payload.');
            }
            $itemIndex = $storedDiscount['order_item_index'] ?? null;
            OrderDiscount::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'order_item_id' => $itemIndex === null
                    ? null
                    : ($itemIds[(int) $itemIndex] ?? null),
                'discount_id' => $storedDiscount['discount_id'],
                'offer_id' => $storedDiscount['offer_id'],
                'name_snapshot' => $storedDiscount['name_snapshot'],
                'amount_type_snapshot' => $storedDiscount['amount_type_snapshot'],
                'amount' => $storedDiscount['amount'],
                'applied_at' => $storedDiscount['applied_at'],
            ]);
        }

        return $itemIds;
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
