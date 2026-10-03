<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\LoadQrPricingInputAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Pricing\PriceResult;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrPricingSnapshotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKOUT_URL = '/api/v1/public/qr/checkout';

    private function createStation(): Device
    {
        return Device::factory()->paired('mdev_qr_pricing_snapshot')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);
    }

    private function createQrSession(Device $station, string $secret): QrSession
    {
        $now = now();

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addMinutes(30),
        ]);
    }

    private function seedProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Snapshot Latte',
            'base_price' => '5.000',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function seedPricingRules(string $orderAmountType, string $orderAmount): void
    {
        $this->insertDiscountRule(1, [
            'name' => 'Targeted line discount',
            'scope' => 'product',
            'amount_type' => 'fixed',
            'amount' => '1.000',
            'auto_apply' => true,
        ]);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => 1,
            'target_type' => 'product',
            'target_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertDiscountRule(2, [
            'name' => 'Selected automatic order discount',
            'scope' => 'order',
            'amount_type' => $orderAmountType,
            'amount' => $orderAmount,
            'auto_apply' => true,
        ]);
        $this->insertDiscountRule(3, [
            'name' => 'Manual order discount must not apply',
            'scope' => 'order',
            'amount_type' => 'fixed',
            'amount' => '8.000',
            'auto_apply' => false,
        ]);
        $this->insertDiscountRule(4, [
            'name' => 'Manager order discount must not apply',
            'scope' => 'order',
            'amount_type' => 'fixed',
            'amount' => '9.500',
            'auto_apply' => true,
            'requires_manager_approval' => true,
        ]);

        DB::table('pos_offers')->insert([
            'id' => 10,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Automatic buy one get one',
            'name_ar' => null,
            'type' => 'bogo',
            'config' => json_encode([
                'buy' => [
                    'product_ids' => [1],
                    'qty' => 1,
                ],
                'get' => [
                    'same_as_buy' => true,
                    'qty' => 1,
                    'percent_off' => 100,
                ],
            ], JSON_THROW_ON_ERROR),
            'auto_apply' => true,
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'max_per_order' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        // LAUNCH-P4 — taxes apply to a VAT-registered merchant (priced on top here).
        $this->registerVat();
        DB::table('pos_taxes')->insert([
            'id' => 20,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'VAT',
            'name_ar' => null,
            'rate_percent' => '5.00',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertDiscountRule(int $id, array $overrides): void
    {
        DB::table('pos_discounts')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Discount '.$id,
            'scope' => 'order',
            'amount_type' => 'fixed',
            'amount' => '1.000',
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'stackable' => false,
            'requires_manager_approval' => false,
            'auto_apply' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    /**
     * @return list<array{product_id: int, qty: int, addon_ids: list<int>, notes: null}>
     */
    private function lines(): array
    {
        return [[
            'product_id' => 1,
            'qty' => 2,
            'addon_ids' => [],
            'notes' => null,
        ]];
    }

    /**
     * @return array{expected: PriceResult, response: TestResponse, order: Order}
     */
    private function checkout(string $secret, string $clientRequestId): array
    {
        $station = $this->createStation();
        $session = $this->createQrSession($station, $secret);
        $lines = $this->lines();
        $loaded = app(LoadQrPricingInputAction::class)->handle(
            100,
            10,
            $lines,
            DateTimeImmutable::createFromInterface(now()),
        );
        $this->assertSame(2, $loaded->autoOrderDiscount?->id);

        $expected = Totals::priceOrder($loaded->pricingInput);
        $response = $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::CHECKOUT_URL, [
            'client_request_id' => $clientRequestId,
            'checkout_choice' => 'machine',
            'phone' => '90001234',
            'plate_number' => '12345 A',
            'lines' => $lines,
        ])->assertCreated();

        return [
            'expected' => $expected,
            'response' => $response,
            'order' => Order::query()->sole(),
        ];
    }

    private function assertHeaderMatches(
        PriceResult $expected,
        TestResponse $response,
        Order $order,
    ): void {
        $this->assertSame($order->uuid, $response->json('data.order.uuid'));
        $this->assertSame($expected->rawSubtotalBaisas, $response->json('data.order.subtotal_baisas'));
        $this->assertSame($expected->discountTotalBaisas, $response->json('data.order.discount_total_baisas'));
        $this->assertSame($expected->taxTotalBaisas, $response->json('data.order.tax_total_baisas'));
        $this->assertSame($expected->grandTotalBaisas, $response->json('data.order.grand_total_baisas'));

        $this->assertSame($expected->rawSubtotalBaisas, Money::toBaisas($order->subtotal));
        $this->assertSame($expected->discountTotalBaisas, Money::toBaisas($order->discount_total));
        $this->assertSame($expected->taxTotalBaisas, Money::toBaisas($order->tax_total));
        $this->assertSame($expected->grandTotalBaisas, Money::toBaisas($order->grand_total));
    }

    private function assertPersistedDiscountRows(PriceResult $expected, Order $order): void
    {
        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $rows = OrderDiscount::query()->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertCount(1, $expected->lineDiscounts);
        $this->assertCount(1, $expected->appliedOffers);
        $this->assertSame(1, $expected->lineDiscounts[0]->ruleId);
        $this->assertSame(10, $expected->appliedOffers[0]->offerId);

        $lineRow = $rows->firstWhere('discount_id', 1);
        $this->assertNotNull($lineRow);
        $this->assertSame((int) $item->id, (int) $lineRow->order_item_id);
        $this->assertNull($lineRow->offer_id);
        $this->assertSame('fixed', $lineRow->amount_type_snapshot);
        $this->assertSame(
            $expected->lineDiscounts[0]->amountBaisas,
            Money::toBaisas($lineRow->amount),
        );

        $orderRow = $rows->firstWhere('discount_id', 2);
        $this->assertNotNull($orderRow);
        $this->assertNull($orderRow->order_item_id);
        $this->assertNull($orderRow->offer_id);
        $this->assertSame(
            $expected->orderDiscountRowBaisas(),
            Money::toBaisas($orderRow->amount),
        );

        $offerRow = $rows->firstWhere('offer_id', 10);
        $this->assertNotNull($offerRow);
        $this->assertNull($offerRow->discount_id);
        $this->assertSame((int) $item->id, (int) $offerRow->order_item_id);
        $this->assertSame('offer', $offerRow->amount_type_snapshot);
        $this->assertSame(
            $expected->appliedOffers[0]->lineAmountsBaisas[0],
            Money::toBaisas($offerRow->amount),
        );

        $rowTotalBaisas = $rows->sum(
            static fn (OrderDiscount $row): int => Money::toBaisas($row->amount),
        );
        $this->assertSame($expected->discountTotalBaisas, $rowTotalBaisas);
        $this->assertSame(Money::toBaisas($order->discount_total), $rowTotalBaisas);
        $this->assertSame(
            $expected->lineDiscountTotalBaisas + $expected->offerDiscountTotalBaisas,
            Money::toBaisas($item->line_discount),
        );

        $this->assertDatabaseMissing('pos_order_discounts', [
            'order_id' => $order->id,
            'discount_id' => 3,
        ]);
        $this->assertDatabaseMissing('pos_order_discounts', [
            'order_id' => $order->id,
            'discount_id' => 4,
        ]);
    }

    public function test_checkout_persists_automatic_discount_offer_and_nonzero_tax_snapshots(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $this->seedProduct();
        $this->seedPricingRules('percent', '10.000');

        ['expected' => $expected, 'response' => $response, 'order' => $order]
            = $this->checkout('pricing-snapshot-secret', 'pricing-snapshot-1');

        $this->assertSame(10000, $expected->rawSubtotalBaisas);
        $this->assertSame(1000, $expected->lineDiscountTotalBaisas);
        $this->assertSame(4500, $expected->offerDiscountTotalBaisas);
        $this->assertSame(1000, $expected->orderDiscountBaisas);
        $this->assertSame(6500, $expected->discountTotalBaisas);
        $this->assertSame(175, $expected->taxTotalBaisas);
        $this->assertSame(3675, $expected->grandTotalBaisas);

        $this->assertHeaderMatches($expected, $response, $order);
        $this->assertPersistedDiscountRows($expected, $order);
    }

    public function test_capped_order_discount_row_keeps_persisted_rows_equal_to_header_total(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $this->seedProduct();
        $this->seedPricingRules('fixed', '9.000');

        ['expected' => $expected, 'response' => $response, 'order' => $order]
            = $this->checkout('pricing-cap-secret', 'pricing-cap-1');

        $this->assertSame(9000, $expected->orderDiscountBaisas);
        $this->assertSame(10000, $expected->discountTotalBaisas);
        $this->assertSame(4500, $expected->orderDiscountRowBaisas());
        $this->assertLessThan($expected->orderDiscountBaisas, $expected->orderDiscountRowBaisas());
        $this->assertSame(0, $expected->taxTotalBaisas);
        $this->assertSame(0, $expected->grandTotalBaisas);

        $this->assertHeaderMatches($expected, $response, $order);
        $this->assertPersistedDiscountRows($expected, $order);
    }

    public function test_overlapping_order_offers_are_capped_when_discount_rows_are_persisted(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $this->seedProduct();

        foreach ([20, 21] as $offerId) {
            DB::table('pos_offers')->insert([
                'id' => $offerId,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Full-order offer '.$offerId,
                'name_ar' => null,
                'type' => 'spend_get',
                'config' => json_encode([
                    'min_subtotal_baisas' => 1000,
                    'reward_type' => 'percent_off',
                    'reward_value' => 100,
                ], JSON_THROW_ON_ERROR),
                'auto_apply' => true,
                'validity_start' => null,
                'validity_end' => null,
                'dayofweek_mask' => null,
                'time_start' => null,
                'time_end' => null,
                'branch_scope_json' => null,
                'max_per_order' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);
        }

        $station = $this->createStation();
        $secret = 'overlapping-offers-secret';
        $session = $this->createQrSession($station, $secret);
        $lines = $this->lines();
        $loaded = app(LoadQrPricingInputAction::class)->handle(
            100,
            10,
            $lines,
            DateTimeImmutable::createFromInterface(now()),
        );
        $expected = Totals::priceOrder($loaded->pricingInput);

        $response = $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::CHECKOUT_URL, [
            'client_request_id' => 'overlapping-offers-1',
            'checkout_choice' => 'machine',
            'phone' => '90001234',
            'plate_number' => '12345 A',
            'lines' => $lines,
        ])->assertCreated();

        $order = Order::query()->sole();
        $rows = OrderDiscount::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $expected->appliedOffers);
        $this->assertSame(20000, $expected->offerDiscountTotalBaisas);
        $this->assertSame(10000, $expected->discountTotalBaisas);
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows->sole()->offer_id);
        $this->assertSame(10000, Money::toBaisas($rows->sole()->amount));
        $this->assertSame(
            $expected->discountTotalBaisas,
            $rows->sum(static fn (OrderDiscount $row): int => Money::toBaisas($row->amount)),
        );
        $this->assertHeaderMatches($expected, $response, $order);
    }
}
