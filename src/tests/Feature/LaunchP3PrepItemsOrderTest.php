<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\QrSession;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-4 (device API, orders) — a prep item (garlic sauce) is used
 * like an ingredient by dishes and add-on options; when the order line's
 * recipe is copied — till/handheld order.create, QR checkout, QR/staff round
 * appends, add-on option lines — it is exploded into the raw ingredients
 * behind it. The copy keeps its shape (raw ingredient lines only), so pay and
 * void deduct the raw ingredients and the prep item never gets a stock row.
 */
class LaunchP3PrepItemsOrderTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $this->seedPrepKitchen();
        Device::factory()->paired('mdev_p3')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function sale(array $lines, int $totalBaisas): array
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(5)->startOfSecond();

        return [
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.create',
                'client_timestamp' => $at->toIso8601String(),
                'payload' => ['order' => [
                    'uuid' => $uuid, 'order_type' => 'dine_in', 'source' => 'main_pos', 'staff_id' => 7,
                    'opened_at' => $at->toIso8601String(),
                    'subtotal_baisas' => $totalBaisas, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => $totalBaisas,
                    'lines' => $lines,
                ]],
            ],
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.pay',
                'client_timestamp' => $at->toIso8601String(),
                'payload' => [
                    'order_uuid' => $uuid, 'paid_at' => $at->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => $totalBaisas, 'change_given_baisas' => 0]],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events): TestResponse
    {
        $response = $this->withToken('mdev_p3')->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
        foreach ($response->json('data.results') as $result) {
            $this->assertSame('processed', $result['status'], (string) json_encode($result));
        }

        return $response;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshot
     * @return array<int, float>
     */
    private function qtyByIngredient(?array $snapshot): array
    {
        $out = [];
        foreach ($snapshot ?? [] as $line) {
            $out[(int) $line['ingredient_id']] = (float) $line['qty'];
        }

        return $out;
    }

    private function consumed(int $ingredientId): float
    {
        return -1 * (float) DB::table('pos_stock_movements')->where('ingredient_id', $ingredientId)->sum('quantity');
    }

    public function test_a_device_sale_copies_the_raw_ingredients_behind_a_prep_item(): void
    {
        $this->push($this->sale([
            ['product_id' => self::SHAWARMA, 'qty' => 2, 'unit_price_baisas' => 1500, 'line_total_baisas' => 3000],
        ], 3000));

        $item = OrderItem::query()->sole();
        // Raw lines only, per ONE unit; garlic merges direct 5 g + 6 g via the sauce.
        $this->assertSame(
            [self::CHICKEN => 150.0, self::BREAD => 1.0, self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0],
            $this->qtyByIngredient($item->recipe_snapshot_json),
        );
        $this->assertSame(['ingredient_id', 'qty', 'unit', 'unit_cost'], array_keys($item->recipe_snapshot_json[0]));
        // The cost of goods (Σ qty × unit_cost) carries the sauce: 0.7065 a unit.
        $cogs = array_sum(array_map(static fn (array $l): float => $l['qty'] * $l['unit_cost'], $item->recipe_snapshot_json));
        $this->assertEqualsWithDelta(0.7065, $cogs, 1e-9);

        // Pay deducted the raw ingredients for 2 units.
        $this->assertSame(22.0, $this->consumed(self::GARLIC));
        $this->assertSame(42.0, $this->consumed(self::OIL));
        $this->assertSame(6.0, $this->consumed(self::LEMON));
        $this->assertSame(958.0, $this->branchBalance(self::OIL));
        $this->assertPrepHasNoStock();
    }

    public function test_add_on_option_lines_with_a_prep_item_explode_and_a_removal_cancels_the_sauce(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_addons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Extra sauce', 'price_delta' => 0.200, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'No sauce', 'price_delta' => 0, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addon_consumptions')->insert([
            ['add_on_id' => 1, 'ingredient_id' => self::SAUCE, 'direction' => 'add', 'quantity' => '20', 'unit' => 'ml', 'display_order' => 0] + $t,
            ['add_on_id' => 2, 'ingredient_id' => self::SAUCE, 'direction' => 'remove', 'quantity' => '30', 'unit' => 'ml', 'display_order' => 0] + $t,
        ]);

        $this->push($this->sale([
            ['product_id' => self::SHAWARMA, 'qty' => 1, 'unit_price_baisas' => 1700, 'line_total_baisas' => 1700, 'addons' => [['add_on_id' => 1, 'price_delta_baisas' => 200]]],
            ['product_id' => self::SHAWARMA, 'qty' => 1, 'unit_price_baisas' => 1500, 'line_total_baisas' => 1500, 'addons' => [['add_on_id' => 2, 'price_delta_baisas' => 0]]],
        ], 3200));

        $extra = OrderItemAddon::query()->where('add_on_id', 1)->sole();
        $this->assertNull($extra->ingredient_snapshot_json);
        // JSON round-trips whole floats as ints — compare loosely.
        $this->assertEquals([
            ['type' => 'ingredient', 'ingredient_id' => self::GARLIC, 'direction' => 'add', 'qty' => 4.0, 'unit' => 'g', 'unit_cost' => 0.002],
            ['type' => 'ingredient', 'ingredient_id' => self::OIL, 'direction' => 'add', 'qty' => 14.0, 'unit' => 'ml', 'unit_cost' => 0.0015],
            ['type' => 'ingredient', 'ingredient_id' => self::LEMON, 'direction' => 'add', 'qty' => 2.0, 'unit' => 'ml', 'unit_cost' => 0.001],
        ], $extra->consumption_snapshot_json);
        $none = OrderItemAddon::query()->where('add_on_id', 2)->sole();
        $this->assertSame(['remove', 'remove', 'remove'], array_column($none->consumption_snapshot_json, 'direction'));

        // Extra sauce: 11+4 garlic, 21+14 oil, 3+2 lemon; no sauce: garlic 5 only.
        $this->assertSame(20.0, $this->consumed(self::GARLIC));
        $this->assertSame(35.0, $this->consumed(self::OIL));
        $this->assertSame(5.0, $this->consumed(self::LEMON));
        $this->assertSame(14.0, -1 * (float) DB::table('pos_stock_movements')
            ->where('ingredient_id', self::OIL)->where('movement_type', 'addon_consumption')->sum('quantity'));
        $this->assertPrepHasNoStock();
    }

    public function test_a_legacy_single_ingredient_add_on_naming_a_prep_item_copies_its_raw_lines(): void
    {
        DB::table('pos_addons')->insert([
            'id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1, 'name' => 'Sauce cup',
            'price_delta' => 0.100, 'ingredient_id' => self::SAUCE, 'ingredient_qty' => '10', 'ingredient_unit' => 'ml',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->push($this->sale([
            ['product_id' => self::SHAWARMA, 'qty' => 1, 'unit_price_baisas' => 1600, 'line_total_baisas' => 1600, 'addons' => [['add_on_id' => 3, 'price_delta_baisas' => 100]]],
        ], 1600));

        $addon = OrderItemAddon::query()->sole();
        $this->assertNull($addon->ingredient_snapshot_json);
        $this->assertSame(
            [[self::GARLIC, 2.0], [self::OIL, 7.0], [self::LEMON, 1.0]],
            array_map(static fn (array $l): array => [$l['ingredient_id'], (float) $l['qty']], $addon->consumption_snapshot_json),
        );
        $this->assertSame(13.0, $this->consumed(self::GARLIC));
        $this->assertSame(28.0, $this->consumed(self::OIL));
        $this->assertPrepHasNoStock();
    }

    public function test_a_qr_checkout_copies_the_raw_ingredients_behind_a_prep_item(): void
    {
        $station = Device::factory()->paired('mdev_p3_station')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'payment_station',
        ]);
        $secret = 'p3-qr-secret-0001';
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret), 'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret])
            ->postJson('/api/v1/public/qr/checkout', [
                'client_request_id' => (string) Str::uuid(),
                'checkout_choice' => 'machine',
                'phone' => '90001234',
                'plate_number' => '12345 A',
                'lines' => [['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ])->assertCreated();

        $item = OrderItem::query()->sole();
        $this->assertSame(
            [self::CHICKEN => 150.0, self::BREAD => 1.0, self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0],
            $this->qtyByIngredient($item->recipe_snapshot_json),
        );
        $this->assertSame('ml', $item->recipe_snapshot_json[3]['unit']);
    }

    public function test_qr_and_staff_round_appends_copy_the_raw_ingredients_behind_a_prep_item(): void
    {
        $lines = [['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [], 'notes' => null]];
        $loaded = app(LoadQrPricingInputAction::class)->handle(100, 10, $lines, DateTimeImmutable::createFromInterface(now()));
        $price = Totals::priceOrder($loaded->pricingInput);
        $context = new QrSession(['company_id' => 100]);
        $append = app(AppendQrPricedLinesAction::class);

        // A round held for review freezes its private payload ...
        $payload = $append->buildPayload($context, $loaded, $price, now());
        $this->assertSame(
            [self::CHICKEN => 150.0, self::BREAD => 1.0, self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0],
            $this->qtyByIngredient($payload['items'][0]['attributes']['recipe_snapshot_json']),
        );

        // ... and an accepted round appends straight to the bill.
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'order_type' => 'dine_in',
            'status' => Order::STATUS_OPEN, 'source' => 'main_pos', 'subtotal' => 0, 'discount_total' => 0,
            'tax_total' => 0, 'grand_total' => 0, 'opened_at' => now(),
        ]);
        $append->handle($order, $context, $loaded, $price, now());
        $this->assertSame(
            [self::CHICKEN => 150.0, self::BREAD => 1.0, self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0],
            $this->qtyByIngredient(OrderItem::query()->where('order_id', $order->id)->sole()->recipe_snapshot_json),
        );
    }
}
