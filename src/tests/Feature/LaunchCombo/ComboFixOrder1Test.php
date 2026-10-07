<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchCombo;

use App\Actions\Qr\AppendQuickQrOrderItemsAction;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH combo add-on, Part A fix order 1 (LAUNCH-COMBO_A_FIX_ORDER_1.md):
 *
 *   C-1  a Remove option's price change reaches the device config delta
 *   C-2  a partial cancel re-splits the children's revenue
 *   C-3  a meal line must name the main's own meal, active and on sale
 *   C-7  the split weighs the items by the order type's prices
 *   C-8  the split base is the line total after the line discount
 *   C-9  every item inside a combo / meal stays at 0 or more
 *   C-10 a replayed quick-order addition compares its items in a stable order
 *   C-13 a till-style line discount ROW (line_index, no line_discount field)
 *        lowers the split base; an order-level row does not
 *
 *   Family box 5.000: Beef burger × 2, Fries, Drinks pick 1. Meal "meal"
 *   +1.200 on Burgers: Fries, Drinks pick 1. Party drinks 6.000: pick 4.
 */
final class ComboFixOrder1Test extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burgers;

    private int $drinks;

    private int $beef;

    private int $chicken;

    private int $fries;

    private int $cola;

    private int $box;

    private int $boxBeef;

    private int $boxFries;

    private int $boxDrink;

    private int $party;

    private int $partyDrinks;

    private int $meal;

    private int $mealDrink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $this->burgers = $this->p4Category('Burgers');
        $this->drinks = $this->p4Category('Drinks');
        $this->beef = $this->p4Product('Beef burger', '2.000', ['category_id' => $this->burgers, 'delivery_price' => '2.500']);
        $this->chicken = $this->p4Product('Chicken burger', '1.800', ['category_id' => $this->burgers]);
        $this->fries = $this->p4Product('Fries', '1.000');
        $this->cola = $this->p4Product('Cola', '1.000', ['category_id' => $this->drinks]);
        $this->box = $this->p4Product('Family box', '5.000', ['product_type' => 'combo']);
        $this->boxBeef = $this->p4FixedLine(['combo_product_id' => $this->box], $this->beef, 2, [], 0);
        $this->boxFries = $this->p4FixedLine(['combo_product_id' => $this->box], $this->fries, 1, [], 1);
        $this->boxDrink = $this->p4ChoiceLine(['combo_product_id' => $this->box], $this->drinks, 1, [], [], 2);
        $this->party = $this->p4Product('Party drinks', '6.000', ['product_type' => 'combo']);
        $this->partyDrinks = $this->p4ChoiceLine(['combo_product_id' => $this->party], $this->drinks, 4);
        $this->meal = $this->p4Meal('meal', '1.200', [$this->burgers], [], ['sort_order' => 1]);
        $this->p4FixedLine(['meal_id' => $this->meal], $this->fries, 1, [], 0);
        $this->mealDrink = $this->p4ChoiceLine(['meal_id' => $this->meal], $this->drinks, 1, [], [], 1);
    }

    private function quote(array $lines): TestResponse
    {
        return $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/quote', ['lines' => $lines]);
    }

    private function mealLine(int $main, int $mealId, ?int $drinkLine = null): array
    {
        return ['product_id' => $main, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'meal_id' => $mealId,
            'combo' => [['line_id' => $drinkLine ?? $this->mealDrink, 'product_id' => $this->cola, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]];
    }

    private function boxWire(array $extra = []): array
    {
        return $extra + ['product_id' => $this->box, 'qty' => 1, 'unit_price_baisas' => 5000, 'line_total_baisas' => 5000, 'combo' => [
            ['line_id' => $this->boxBeef, 'kind' => 'fixed', 'product_id' => $this->beef, 'qty' => 2, 'extra_price_baisas' => 0],
            ['line_id' => $this->boxFries, 'kind' => 'fixed', 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0],
            ['line_id' => $this->boxDrink, 'kind' => 'choice', 'product_id' => $this->cola, 'qty' => 1, 'extra_price_baisas' => 0],
        ]];
    }

    /** @return list<int|null> the order's children's shares, in id order */
    private function shares(string $uuid): array
    {
        $order = Order::query()->where('uuid', $uuid)->sole();

        return OrderItem::query()->where('order_id', $order->id)->whereNotNull('parent_order_item_id')->orderBy('id')
            ->pluck('allocated_revenue_baisas')->map(static fn ($v): ?int => $v === null ? null : (int) $v)->all();
    }

    public function test_c1_a_remove_option_price_change_rides_the_device_config_delta(): void
    {
        $noCheese = $this->p4Addons($this->beef, ['NO Cheese' => '0.000'], ['name' => 'Remove', 'kind' => 'remove'])['NO Cheese'];
        DB::table('pos_addon_groups')->update(['updated_at' => '2026-10-01 08:00:00']);
        $this->p4Device('mdev_fix1_c1');
        $cursor = now()->toIso8601String();
        $this->travel(5)->minutes();
        // The portal writes the price and touches the group (fix C-1 in pos_merchant).
        DB::table('pos_addons')->where('id', $noCheese)->update(['price_delta' => '-0.100', 'updated_at' => now()]);
        DB::table('pos_addon_groups')->update(['updated_at' => now()]);
        $delta = $this->withToken('mdev_fix1_c1')->getJson('/api/v1/device/config/delta?since='.urlencode($cursor))->assertOk()->json('data');
        $group = collect($delta['addon_groups'])->firstWhere('name', 'Remove');
        $this->assertSame(-100, $group['addons'][0]['price_delta_baisas']);
    }

    public function test_c2_a_partial_cancel_resplits_the_children_to_the_new_paid_total(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $line = ['product_id' => $this->box, 'qty' => 2, 'addon_ids' => [], 'notes' => null, 'combo' => [
            ['line_id' => $this->boxDrink, 'product_id' => $this->cola, 'qty' => 1, 'addons' => []],
        ]];
        $push = fn (string $type, array $payload): array => $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type, 'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ]]])->assertOk()->json('data.results.0');
        $base = ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false];
        $ack = $push('table.session.round', $base + ['client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [$line]]);
        $this->assertSame('processed', $ack['status'], (string) json_encode($ack));
        $children = fn (): array => OrderItem::query()->whereNotNull('parent_order_item_id')->orderBy('id')->pluck('allocated_revenue_baisas')->map(static fn ($v): int => (int) $v)->all();
        $this->assertSame([6668, 1666, 1666], $children());

        $cancel = $push('table.session.cancel_line', $base + ['client_request_id' => (string) Str::uuid(), 'product_id' => $this->box, 'qty' => 1,
            'prepared' => false, 'cancelled_at' => now()->toIso8601String(), 'staff_id' => 7]);
        $this->assertSame('processed', $cancel['status'], (string) json_encode($cancel));
        // One box left: the children add up to 5.000 again.
        $this->assertSame([3334, 833, 833], $children());
        $this->assertSame(5000, array_sum($children()));
    }

    public function test_c3_a_meal_line_names_the_mains_own_active_meal_on_sale(): void
    {
        // A second active meal on the same category slipped in (lower priority): only the first is the main's meal.
        $big = $this->p4Meal('Big meal', '2.000', [$this->burgers], [], ['sort_order' => 2]);
        $bigDrink = $this->p4ChoiceLine(['meal_id' => $big], $this->drinks, 1);
        $this->quote([$this->mealLine($this->beef, $this->meal)])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3200);
        $this->quote([$this->mealLine($this->beef, $big, $bigDrink)])->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');

        $this->p4Device('mdev_fix1_c3');
        $wire = fn (int $mealId): array => ['product_id' => $this->beef, 'meal_id' => $mealId, 'qty' => 1, 'unit_price_baisas' => 3200,
            'line_total_baisas' => 3200, 'combo' => [['line_id' => $this->mealDrink, 'kind' => 'choice', 'product_id' => $this->cola, 'qty' => 1, 'extra_price_baisas' => 0]]];
        $check = fn (int $mealId): array => $this->p4Push('mdev_fix1_c3', [$this->p4Event('order.create', $this->p4Order([$wire($mealId)]))])
            ->json('data.results.0.result.pricing_check');
        $this->assertTrue($check($this->meal)['match']);
        $this->assertSame('combo', $check($big)['failures'][0]['code']);

        // Inactive, or expired: refused on QR / tablet, flagged on devices.
        DB::table('pos_meals')->where('id', $this->meal)->update(['status' => 'inactive']);
        $this->quote([$this->mealLine($this->chicken, $this->meal)])->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');
        $this->assertFalse($check($this->meal)['match']);
        DB::table('pos_meals')->where('id', $this->meal)->update(['status' => 'active', 'on_sale_until' => '2026-10-06']);
        $this->quote([$this->mealLine($this->chicken, $this->meal)])->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');
        $this->assertFalse($check($this->meal)['match']);
        // With the first meal expired, the big meal is the main's meal now.
        $this->quote([$this->mealLine($this->beef, $big, $bigDrink)])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 4000);
    }

    public function test_c7_a_delivery_order_splits_by_the_delivery_prices(): void
    {
        $this->p4Device('mdev_fix1_c7');
        $order = $this->p4Order([$this->boxWire()], 0, null, ['order_type' => 'delivery']);
        $result = $this->p4Push('mdev_fix1_c7', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        // Weights: Beef 2.500 (delivery) × 2, Fries 1.000, Cola 1.000 → 1.786 + 1.786 + 0.714 + 0.714.
        $this->assertSame([3572, 714, 714], $this->shares($order['uuid']));
        // In store, the in-store prices.
        $store = $this->p4Order([$this->boxWire()]);
        $this->p4Push('mdev_fix1_c7', [$this->p4Event('order.create', $store)]);
        $this->assertSame([3334, 833, 833], $this->shares($store['uuid']));
    }

    public function test_c8_the_split_base_is_the_line_total_after_the_line_discount(): void
    {
        // Device: a 10% line discount (0.500) → the children add up to 4.500.
        $this->p4Device('mdev_fix1_c8');
        $order = $this->p4Order([$this->boxWire(['line_discount_baisas' => 500])], 0, null, [
            'discount_total_baisas' => 500, 'grand_total_baisas' => 4500,
            'discounts' => [['discount_id' => null, 'name' => 'Ten off', 'amount_type' => 'percent', 'amount_baisas' => 500, 'line_index' => 0]],
        ]);
        $result = $this->p4Push('mdev_fix1_c8', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame(4500, array_sum($this->shares($order['uuid'])));
        $this->assertSame([3000, 750, 750], $this->shares($order['uuid']));

        // QR: an automatic 10% product discount on the box.
        DB::table('pos_discounts')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Box 10%', 'scope' => 'product',
            'amount_type' => 'percent', 'amount' => '10.000', 'stackable' => false, 'requires_manager_approval' => false, 'auto_apply' => true,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_discount_targets')->insert(['discount_id' => 1, 'target_type' => 'product', 'target_id' => $this->box, 'created_at' => now(), 'updated_at' => now()]);
        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/checkout', $this->p4QrCheckout([
            ['product_id' => $this->box, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [
                ['line_id' => $this->boxDrink, 'product_id' => $this->cola, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]],
        ]))->assertStatus(201)->assertJsonPath('data.order.grand_total_baisas', 4500);
        $qr = (string) Order::query()->orderByDesc('id')->value('uuid');
        $this->assertSame([3000, 750, 750], $this->shares($qr));
    }

    public function test_c9_an_item_inside_a_combo_never_goes_below_0(): void
    {
        // 4 × Cola with "No ice −0.500": each Cola item is 0, never −0.500 (the box stays 6.000).
        $noIce = $this->p4Addons($this->cola, ['NO Ice' => '-0.500'], ['name' => 'Remove', 'kind' => 'remove'])['NO Ice'];
        $this->quote([['product_id' => $this->party, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [
            ['line_id' => $this->partyDrinks, 'product_id' => $this->cola, 'qty' => 4, 'addon_ids' => [$noIce], 'notes' => ''],
        ]]])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 6000)
            ->assertJsonPath('data.quote.lines.0.components.0.price_baisas', 0);
        // A main inside a meal is an item too: Beef 2.000 with a −2.500 remove counts 0, the meal stays 1.200.
        $noBun = $this->p4Addons($this->beef, ['NO Bun' => '-2.500'], ['name' => 'Remove', 'kind' => 'remove'])['NO Bun'];
        $this->quote([['product_id' => $this->beef, 'qty' => 1, 'addon_ids' => [$noBun], 'notes' => '', 'meal_id' => $this->meal, 'combo' => [
            ['line_id' => $this->mealDrink, 'product_id' => $this->cola, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]]])
            ->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 1200);
    }

    public function test_c10_a_replayed_addition_compares_its_items_in_a_stable_order(): void
    {
        $method = new ReflectionMethod(AppendQuickQrOrderItemsAction::class, 'requestLines');
        $action = app(AppendQuickQrOrderItemsAction::class);
        $item = fn (int $productId, int $qty): array => ['line_id' => $this->partyDrinks, 'product_id' => $productId, 'qty' => $qty, 'addon_ids' => [], 'notes' => ''];
        $sent = [['product_id' => $this->party, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [$item($this->cola, 3), $item($this->fries, 1)]]];
        $frozen = [['product_id' => $this->party, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [$item($this->fries, 1), $item($this->cola, 3)]]];
        $this->assertSame($method->invoke($action, $sent), $method->invoke($action, $frozen));
    }

    public function test_c13_a_till_style_line_discount_row_lowers_the_split_base(): void
    {
        // The till sends the 10% line discount as a discount ROW aimed at the
        // line (line_index), not in line_discount_baisas; an order-level row
        // (no line_index) is not spread.
        $this->p4Device('mdev_fix1_c13');
        $rows = [
            ['discount_id' => null, 'name' => 'Ten off the box', 'amount_type' => 'percent', 'amount_baisas' => 500, 'line_index' => 0],
            ['discount_id' => null, 'name' => 'Order 0.200 off', 'amount_type' => 'fixed', 'amount_baisas' => 200],
        ];
        $money = ['discount_total_baisas' => 700, 'grand_total_baisas' => 4300, 'discounts' => $rows];
        $order = $this->p4Order([$this->boxWire()], 0, null, $money);
        $result = $this->p4Push('mdev_fix1_c13', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame([3000, 750, 750], $this->shares($order['uuid']));

        // Shares the till worked out itself (adding up to 4.500) are kept, and
        // the pricing check does not flag the combo.
        $wire = $this->boxWire();
        foreach ([3000, 750, 750] as $i => $share) {
            $wire['combo'][$i]['allocated_revenue_baisas'] = $share;
        }
        $sent = $this->p4Order([$wire], 0, null, $money);
        $check = $this->p4Push('mdev_fix1_c13', [$this->p4Event('order.create', $sent)])->json('data.results.0.result.pricing_check');
        $this->assertSame([3000, 750, 750], $this->shares($sent['uuid']));
        $this->assertNotContains('combo', array_column($check['failures'] ?? [], 'code'), (string) json_encode($check));
    }
}
