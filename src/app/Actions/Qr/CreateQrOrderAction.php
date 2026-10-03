<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Pricing\AppliedOfferResult;
use App\Support\Pricing\LineDiscountResult;
use App\Support\Pricing\PriceResult;
use App\Support\Pricing\Totals;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Server-authoritative QR order writer; no client money enters this action. */
final class CreateQrOrderAction
{
    public function __construct(
        private readonly LoadQrPricingInputAction $pricing,
        private readonly ResolveQrCustomerAction $customers,
        private readonly DistinctQrPhoneGuard $phoneGuard,
        private readonly AllocateQrTempReferenceAction $tempReferences,
        private readonly OrderLineSnapshotter $snapshots,
        private readonly QrComboChildren $children,
    ) {}

    /**
     * @param  array{client_request_id: string, checkout_choice: string, phone: string, plate_number?: string|null, lines: list<array<string, mixed>>}  $payload
     */
    public function handle(int $sessionId, array $payload, string $ip): Order
    {
        $result = DB::transaction(function () use ($sessionId, $payload, $ip): Order|QrCheckoutException {
            $session = QrSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                return new QrCheckoutException('qr_session_not_found', 404, 'QR session was not found.');
            }

            $clientRequestId = (string) $payload['client_request_id'];
            $existing = Order::query()
                ->where('qr_session_id', $session->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $now = now();
            if ($session->expires_at === null || $session->expires_at->lte($now)) {
                QrSession::query()
                    ->whereKey($session->id)
                    ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
                    ->where('expires_at', '<=', $now)
                    ->update([
                        'status' => QrSession::STATUS_EXPIRED,
                        'closed_at' => $now,
                        'updated_at' => $now,
                    ]);

                return new QrCheckoutException('qr_session_not_found', 404, 'QR session was not found.');
            }
            if ($session->status !== QrSession::STATUS_ACTIVE) {
                throw new QrCheckoutException(
                    'qr_session_already_ordered',
                    409,
                    'This QR session already has an order.',
                );
            }

            $device = Device::query()->withTrashed()->whereKey($session->device_id)->lockForUpdate()->first();
            if (! $this->isUsableStation($session, $device)) {
                throw new QrCheckoutException('qr_session_not_found', 404, 'QR session was not found.');
            }

            $loaded = $this->pricing->handle(
                (int) $session->company_id,
                (int) $session->branch_id,
                $payload['lines'],
                DateTimeImmutable::createFromInterface($now),
            );
            $price = Totals::priceOrder($loaded->pricingInput);
            // LAUNCH-P2 P2-7 — sell, but warn: the shelf count never refuses a
            // QR order (it used to: AssertQrStockAvailableAction, removed).

            $phone = (string) $payload['phone'];
            if (! $this->phoneGuard->allows(
                (string) $session->uuid,
                (int) $session->branch_id,
                $ip,
                $phone,
            )) {
                throw new QrCheckoutException(
                    'qr_identity_limit_exceeded',
                    429,
                    'Too many customer identities were submitted.',
                );
            }

            // Identity resolution is intentionally after cart validation and
            // pricing, inside this transaction, and never influences the
            // public response shape.
            $customer = $this->customers->handle(
                (int) $session->company_id,
                $phone,
                isset($payload['plate_number']) ? (string) $payload['plate_number'] : null,
            );

            $choice = (string) $payload['checkout_choice'];
            $status = $choice === 'machine'
                ? Order::STATUS_AWAITING_PAYMENT
                : Order::STATUS_HELD;

            $order = Order::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $session->company_id,
                'branch_id' => $session->branch_id,
                'device_id' => $device->id,
                'qr_session_id' => $session->id,
                'client_request_id' => $clientRequestId,
                'staff_id' => null,
                'customer_id' => $customer->customerId,
                'table_id' => null,
                'order_type' => 'quick',
                'status' => $status,
                'source' => Order::SOURCE_QR_WEB,
                'plate_number' => $customer->plateNumber,
                'subtotal' => Money::toOmr($price->rawSubtotalBaisas),
                'discount_total' => Money::toOmr($price->discountTotalBaisas),
                'comp_total' => Money::toOmr(0),
                'tax_total' => Money::toOmr($price->taxTotalBaisas),
                'grand_total' => Money::toOmr($price->grandTotalBaisas),
                'prices_include_tax' => $price->pricesIncludeTax,
                'opened_at' => $now,
                'closed_at' => null,
                'client_event_id' => null,
                'receipt_number' => null,
                'temp_reference' => $this->tempReferences->handle(
                    (int) $session->company_id,
                    (int) $session->branch_id,
                ),
            ]);

            $itemIds = [];
            foreach ($loaded->resolvedLines as $index => $resolved) {
                $productSnapshots = $this->snapshots->product($resolved->product);
                $item = OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $resolved->product->id,
                    'product_name_snapshot' => $resolved->product->name,
                    'qty' => $resolved->qty,
                    // Base and add-on prices are frozen separately; line_total
                    // is the authoritative combined gross for this quantity.
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
                // LAUNCH-P4 — a combo line's chosen items become its children.
                if ($resolved->isCombo()) {
                    $this->children->write($item, $this->children->payload($resolved, (int) $session->company_id));
                }
            }

            $this->writeDiscountRows($order, $loaded, $price, $itemIds, $now);

            $session->update([
                'status' => QrSession::STATUS_ORDERED,
                'last_seen_at' => $now,
            ]);

            return $order->fresh();
        }, 5);

        if ($result instanceof QrCheckoutException) {
            throw $result;
        }

        return $result;
    }

    private function isUsableStation(QrSession $session, ?Device $device): bool
    {
        return $device !== null
            && ! $device->trashed()
            && $device->status === 'active'
            && $device->isAssigned()
            && $device->isPaymentStation()
            && (int) $device->company_id === (int) $session->company_id
            && (int) $device->branch_id === (int) $session->branch_id;
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

    /**
     * @param  array<int, int>  $itemIds
     */
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

        $orderDiscountRowBaisas = $price->orderDiscountRowBaisas();
        if ($orderDiscountRowBaisas > 0 && $loaded->autoOrderDiscount !== null) {
            OrderDiscount::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'order_item_id' => null,
                'discount_id' => $loaded->autoOrderDiscount->id,
                'offer_id' => null,
                'name_snapshot' => $loaded->autoOrderDiscount->name,
                'amount_type_snapshot' => $loaded->autoOrderDiscount->amountType,
                'amount' => Money::toOmr($orderDiscountRowBaisas),
                'applied_at' => $appliedAt,
            ]);
        }

        // Totals caps the aggregate discount at the raw subtotal while the
        // engine deliberately retains each offer's uncapped diagnostic
        // result. Persist only the portion that fits the authoritative header
        // so discount rows and reports always reconcile to discount_total.
        $remainingOfferBaisas = max(
            0,
            $price->discountTotalBaisas
                - $price->lineDiscountTotalBaisas
                - $orderDiscountRowBaisas,
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
    private function writeLineDiscount(Order $order, LineDiscountResult $discount, array $itemIds, CarbonInterface $appliedAt): void
    {
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
            $this->writeOfferDiscount($order, $offer, $amount, $itemIds[(int) $lineIndex] ?? null, $appliedAt);
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
