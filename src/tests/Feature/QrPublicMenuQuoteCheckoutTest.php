<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Pricing\PricingInput;
use App\Support\Pricing\PricingLine;
use App\Support\Pricing\TaxSpec;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrPublicMenuQuoteCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'public-menu-quote-secret';

    /** @var list<string> */
    private const PRICE_KEYS = [
        'price',
        'price_baisas',
        'price_display',
        'base_price',
        'base_price_baisas',
        'base_price_display',
        'unit_price',
        'unit_price_baisas',
        'unit_price_display',
        'price_delta',
        'price_delta_baisas',
        'price_delta_display',
        'addon_total',
        'addon_total_baisas',
        'addon_total_display',
        'line_total',
        'line_total_baisas',
        'line_total_display',
        'line_discount',
        'line_discount_baisas',
        'line_discount_display',
        'subtotal',
        'subtotal_baisas',
        'subtotal_display',
        'discount_amount',
        'discount_amount_baisas',
        'discount_amount_display',
        'discount_total',
        'discount_total_baisas',
        'discount_total_display',
        'tax_amount',
        'tax_amount_baisas',
        'tax_amount_display',
        'tax_total',
        'tax_total_baisas',
        'tax_total_display',
        'comp_amount',
        'comp_amount_baisas',
        'comp_amount_display',
        'comp_total',
        'comp_total_display',
        'grand_total_baisas',
        'comp_total_baisas',
        'grand_total',
        'grand_total_display',
    ];

    private int $nextProductId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_public_menu_keeps_branch_unavailable_product_and_excludes_other_branch_product(): void
    {
        $session = $this->activeSession();
        $unavailable = $this->product(['name' => 'Unavailable here']);
        $otherBranch = $this->product(['name' => 'Other branch only']);
        $this->branchProduct($unavailable, false, null, 10);
        $this->branchProduct($otherBranch, true, null, 20);

        $response = $this->qrGet($session, '/api/v1/public/qr/menu')
            ->assertOk()
            ->assertJsonPath('meta.money_unit', 'baisas');
        $products = collect($response->json('data.products'))->keyBy('id');

        $this->assertTrue($products->has($unavailable));
        $this->assertFalse($products[$unavailable]['available']);
        $this->assertSame('branch_unavailable', $products[$unavailable]['unavailable_reason']);
        $this->assertFalse($products->has($otherBranch));
    }

    public function test_quote_accepts_blank_and_null_notes_and_never_writes_an_order(): void
    {
        $session = $this->activeSession();
        $product = $this->product(['base_price' => '2.000']);

        foreach (['', null] as $notes) {
            $response = $this->qrPost($session, '/api/v1/public/qr/quote', [
                'lines' => [[
                    'product_id' => $product,
                    'qty' => 2,
                    'addon_ids' => [],
                    'notes' => $notes,
                ]],
            ]);

            $response->assertOk()
                ->assertJsonPath('data.quote.subtotal_baisas', 4000)
                ->assertJsonPath('data.quote.grand_total_baisas', 4000)
                ->assertJsonPath('meta.money_unit', 'baisas');
            $this->assertDatabaseCount('pos_orders', 0);
        }
    }

    public function test_checkout_rejects_an_unavailable_product_without_writing_an_order(): void
    {
        $session = $this->activeSession();
        $product = $this->product();
        $this->branchProduct($product, false, null, 10);

        $this->qrPost($session, '/api/v1/public/qr/checkout', $this->checkoutPayload($product))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');

        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_product_backed_addons_are_greyed_and_rejected_by_live_branch_availability(): void
    {
        $session = $this->activeSession();
        $parent = $this->product(['name' => 'Coffee']);
        $branchDisabled = $this->product([
            'name' => 'Disabled cake',
            'show_on_customer_tablet' => false,
        ]);
        $zeroStock = $this->product([
            'name' => 'Sold-out cup',
            'stock_mode' => 'unit',
            'show_on_customer_tablet' => false,
        ]);
        $addonOnly = $this->product([
            'name' => 'Add-on-only syrup',
            'show_on_customer_tablet' => false,
        ]);
        $inactive = $this->product([
            'name' => 'Inactive topping',
            'status' => 'inactive',
            'show_on_customer_tablet' => false,
        ]);
        $deleted = $this->product([
            'name' => 'Deleted topping',
            'show_on_customer_tablet' => false,
            'deleted_at' => now(),
        ]);
        $outOfWindow = $this->product([
            'name' => 'Breakfast topping',
            'show_on_customer_tablet' => false,
            'available_from' => '06:00:00',
            'available_until' => '10:00:00',
        ]);
        $this->branchProduct($branchDisabled, false, null, 10);
        $this->branchProduct($zeroStock, true, '0.000', 10);

        $this->addonGroup(50);
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 50,
            'product_id' => $parent,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addon(60, 50, '0.500', ['linked_product_id' => $branchDisabled]);
        $this->addon(61, 50, '0.100');
        $this->addon(62, 50, '0.250', ['linked_product_id' => $addonOnly]);
        $this->addon(63, 50, '0.000');
        $this->addon(64, 50, '0.100', ['linked_product_id' => $inactive]);
        $this->addon(65, 50, '0.100', ['linked_product_id' => $deleted]);
        $this->addon(66, 50, '0.100', ['linked_product_id' => $outOfWindow]);
        DB::table('pos_addon_consumptions')->insert([
            [
                'add_on_id' => 61,
                'ingredient_id' => null,
                'component_product_id' => $zeroStock,
                'direction' => 'add',
                'quantity' => '1.000',
                'unit' => null,
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'add_on_id' => 63,
                'ingredient_id' => null,
                'component_product_id' => $zeroStock,
                'direction' => 'add',
                'quantity' => '1.000',
                'unit' => null,
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'add_on_id' => 63,
                'ingredient_id' => null,
                'component_product_id' => $zeroStock,
                'direction' => 'remove',
                'quantity' => '1.000',
                'unit' => null,
                'display_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $menu = $this->qrGet($session, '/api/v1/public/qr/menu')->assertOk();
        $products = collect($menu->json('data.products'))->keyBy('id');
        $addons = collect($menu->json('data.addon_groups.0.addons'))->keyBy('id');

        $this->assertFalse($products->has($addonOnly));
        $this->assertSame($branchDisabled, $addons[60]['linked_product_id']);
        $this->assertFalse($addons[60]['available']);
        $this->assertSame('branch_unavailable', $addons[60]['unavailable_reason']);
        $this->assertFalse($addons[61]['available']);
        $this->assertSame('out_of_stock', $addons[61]['unavailable_reason']);
        $this->assertTrue($addons[62]['available']);
        $this->assertNull($addons[62]['unavailable_reason']);
        $this->assertTrue($addons[63]['available']);
        $this->assertNull($addons[63]['unavailable_reason']);
        $this->assertFalse($addons[64]['available']);
        $this->assertSame('inactive', $addons[64]['unavailable_reason']);
        $this->assertFalse($addons[65]['available']);
        $this->assertSame('linked_product_unavailable', $addons[65]['unavailable_reason']);
        $this->assertFalse($addons[66]['available']);
        $this->assertSame('outside_availability_window', $addons[66]['unavailable_reason']);

        foreach ([60, 61, 64, 65, 66] as $addonId) {
            foreach (['/api/v1/public/qr/quote', '/api/v1/public/qr/checkout'] as $endpoint) {
                $payload = str_ends_with($endpoint, '/quote')
                    ? ['lines' => [$this->line($parent, [$addonId])]]
                    : $this->checkoutPayload($parent);
                $payload['lines'][0]['addon_ids'] = [$addonId];

                $this->qrPost($session, $endpoint, $payload)
                    ->assertStatus(422)
                    ->assertJsonPath('errors.0.code', 'addon_unavailable');
            }
        }
        $this->assertDatabaseCount('pos_orders', 0);

        $this->qrPost($session, '/api/v1/public/qr/quote', [
            'lines' => [$this->line($parent, [63])],
        ])->assertOk();
        $this->qrPost($session, '/api/v1/public/qr/checkout', [
            'client_request_id' => 'addon-only-product-1',
            'checkout_choice' => 'machine',
            'phone' => '+96890000001',
            'lines' => [$this->line($parent, [62])],
        ])->assertCreated();

        $this->assertDatabaseHas('pos_order_item_addons', [
            'add_on_id' => 62,
            'linked_product_id' => $addonOnly,
        ]);
    }

    public function test_every_price_bearing_key_is_rejected_by_quote_and_checkout_at_any_depth_and_value(): void
    {
        $session = $this->activeSession();
        $product = $this->product();
        $values = [0, null, '', 1234];
        $endpoints = ['/api/v1/public/qr/quote', '/api/v1/public/qr/checkout'];

        foreach ($endpoints as $endpoint) {
            foreach (['top', 'line'] as $location) {
                foreach (self::PRICE_KEYS as $key) {
                    foreach ($values as $value) {
                        $payload = str_ends_with($endpoint, '/quote')
                            ? ['lines' => [$this->line($product)]]
                            : $this->checkoutPayload($product);
                        if ($location === 'top') {
                            $payload[$key] = $value;
                        } else {
                            $payload['lines'][0][$key] = $value;
                        }

                        $label = $endpoint.' '.$location.' '.$key.'='.json_encode($value);
                        $response = $this->qrPost($session, $endpoint, $payload);
                        $this->assertSame(422, $response->status(), $label);
                        $this->assertSame(
                            'client_priced_payload_rejected',
                            $response->json('errors.0.code'),
                            $label,
                        );
                    }
                }
            }
        }

        // Includes plausible tampering such as unit_price_baisas=1234; none
        // may reach the order transaction or write a partial order.
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_checkout_money_matches_an_independent_existing_engine_calculation(): void
    {
        $session = $this->activeSession();
        $product = $this->product(['base_price' => '2.000']);
        $this->addonGroup(50);
        $this->addon(60, 50, '0.250');
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 50,
            'product_id' => $product,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tax(1, 'VAT', '5.00', true);

        // Independent expected value: no loader or HTTP response data feeds
        // this engine input. Catalogue facts are stated again in the test.
        $expected = Totals::priceOrder(new PricingInput(
            lines: [new PricingLine(
                unitPriceBaisas: 2250,
                qty: 2,
                productId: $product,
            )],
            now: new DateTimeImmutable('2026-08-26 12:00:00'),
            taxes: [new TaxSpec('VAT', 5.0)],
            branchId: 10,
        ));

        $this->qrPost($session, '/api/v1/public/qr/checkout', [
            'client_request_id' => 'independent-money-1',
            'checkout_choice' => 'machine',
            'phone' => '+96890000001',
            'lines' => [$this->line($product, [60], 2)],
        ])->assertStatus(201)
            ->assertJsonPath('data.order.subtotal_baisas', $expected->rawSubtotalBaisas)
            ->assertJsonPath('data.order.discount_total_baisas', $expected->discountTotalBaisas)
            ->assertJsonPath('data.order.tax_total_baisas', $expected->taxTotalBaisas)
            ->assertJsonPath('data.order.grand_total_baisas', $expected->grandTotalBaisas);

        $order = DB::table('pos_orders')->sole();
        $this->assertSame($expected->rawSubtotalBaisas, Money::toBaisas($order->subtotal));
        $this->assertSame($expected->discountTotalBaisas, Money::toBaisas($order->discount_total));
        $this->assertSame($expected->taxTotalBaisas, Money::toBaisas($order->tax_total));
        $this->assertSame($expected->grandTotalBaisas, Money::toBaisas($order->grand_total));
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame('qr_web', $order->source);

        $item = DB::table('pos_order_items')->where('order_id', $order->id)->sole();
        $this->assertSame(2250, Money::toBaisas($item->unit_price_snapshot));
        $this->assertSame(4500, Money::toBaisas($item->line_total));
        $addon = DB::table('pos_order_item_addons')->where('order_item_id', $item->id)->sole();
        $this->assertSame(250, Money::toBaisas($addon->price_delta_snapshot));
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);
    }

    private function activeSession(): QrSession
    {
        $device = Device::factory()->paired('mdev_qr_http_'.Str::random(24))->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
            'status' => 'active',
        ]);

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $device->id,
            'token' => Str::random(64),
            'token_expires_at' => now()->addMinute(),
            'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret(self::SECRET),
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /** @return array<string, string> */
    private function headers(QrSession $session): array
    {
        return [
            'X-QR-Session' => (string) $session->uuid,
            'X-QR-Client-Secret' => self::SECRET,
        ];
    }

    private function qrGet(QrSession $session, string $uri): TestResponse
    {
        return $this->withHeaders($this->headers($session))->getJson($uri);
    }

    /** @param array<string, mixed> $payload */
    private function qrPost(QrSession $session, string $uri, array $payload): TestResponse
    {
        return $this->withHeaders($this->headers($session))->postJson($uri, $payload);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(int $productId): array
    {
        return [
            'client_request_id' => 'checkout-'.Str::random(24),
            'checkout_choice' => 'machine',
            'phone' => '+96890000001',
            'lines' => [$this->line($productId)],
        ];
    }

    /** @return array{product_id: int, qty: int, addon_ids: list<int>, notes: string} */
    private function line(int $productId, array $addonIds = [], int $qty = 1): array
    {
        return [
            'product_id' => $productId,
            'qty' => $qty,
            'addon_ids' => $addonIds,
            'notes' => '',
        ];
    }

    private function product(array $overrides = []): int
    {
        $id = (int) ($overrides['id'] ?? $this->nextProductId++);
        DB::table('pos_products')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Product '.$id,
            'base_price' => '1.000',
            'stock_mode' => 'untracked',
            'display_order' => $id,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        return $id;
    }

    private function branchProduct(int $productId, bool $available, ?string $stock, int $branchId): void
    {
        DB::table('pos_branch_product')->insert([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'is_available' => $available,
            'stock_qty' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addonGroup(int $id): void
    {
        DB::table('pos_addon_groups')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Options '.$id,
            'selection_mode' => 'multiple',
            'is_global' => false,
            'display_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function addon(int $id, int $groupId, string $priceDelta, array $overrides = []): void
    {
        DB::table('pos_addons')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'add_on_group_id' => $groupId,
            'name' => 'Option '.$id,
            'price_delta' => $priceDelta,
            'display_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tax(int $id, string $name, string $rate, bool $active): void
    {
        DB::table('pos_taxes')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => $name,
            'rate_percent' => $rate,
            'is_active' => $active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
