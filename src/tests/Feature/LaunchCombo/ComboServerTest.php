<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchCombo;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH combo add-on, Part A item 6 (LAUNCH-COMBO_WORK_ORDER.md §1, §2.3–2.5):
 * combos and meals as lists of lines on the server — the pricer (QR and
 * tablet), the writers (children with kinds, line ids and revenue shares),
 * stock, the device config, the QR / tablet menu, the device order wire,
 * the pricing check and the kitchen / receipt data.
 *
 *   Burgers: Beef burger 2.000, Chicken burger 1.800.
 *   Sides:   Fries 1.000 (shelf 20), Loaded fries 1.500 (shelf 10).
 *   Drinks:  Cola 1.000, Juice 1.200, Water 0.300.
 *   "Family box" 5.000: Beef burger × 2 (fixed), Fries (fixed, upgrade
 *     Loaded fries +0.800), Drinks pick 1 (Juice +0.300, Water unticked).
 *   "Party drinks" 6.000: Drinks pick 4 (Juice +0.300, Water unticked).
 *   "Duo" 3.000: Beef burger + Fries (fixed only).
 *   Meal "meal" +1.200 on Burgers: Fries (fixed, upgrade Loaded fries
 *     +0.800), Drinks pick 1 (Juice +0.300).
 */
final class ComboServerTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burgers;

    private int $drinks;

    private int $beef;

    private int $chicken;

    private int $fries;

    private int $loaded;

    private int $cola;

    private int $juice;

    private int $water;

    private int $box;

    private int $boxBeef;

    private int $boxFries;

    private int $boxDrink;

    private int $party;

    private int $partyDrinks;

    private int $duo;

    private int $meal;

    private int $mealFries;

    private int $mealDrink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $t = ['created_at' => now(), 'updated_at' => now()];
        $this->burgers = $this->p4Category('Burgers');
        $sides = $this->p4Category('Sides');
        $this->drinks = $this->p4Category('Drinks');
        $this->beef = $this->p4Product('Beef burger', '2.000', ['category_id' => $this->burgers, 'cooking_minutes' => 12]);
        $this->chicken = $this->p4Product('Chicken burger', '1.800', ['category_id' => $this->burgers]);
        $this->fries = $this->p4Product('Fries', '1.000', ['category_id' => $sides, 'stock_mode' => 'unit']);
        $this->loaded = $this->p4Product('Loaded fries', '1.500', ['category_id' => $sides, 'stock_mode' => 'unit']);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => $this->fries, 'is_available' => true, 'stock_qty' => '20.000'] + $t,
            ['branch_id' => 10, 'product_id' => $this->loaded, 'is_available' => true, 'stock_qty' => '10.000'] + $t,
        ]);
        $this->cola = $this->p4Product('Cola', '1.000', ['category_id' => $this->drinks]);
        $this->juice = $this->p4Product('Juice', '1.200', ['category_id' => $this->drinks]);
        $this->water = $this->p4Product('Water', '0.300', ['category_id' => $this->drinks]);

        $this->box = $this->p4Product('Family box', '5.000', ['product_type' => 'combo']);
        $this->boxBeef = $this->p4FixedLine(['combo_product_id' => $this->box], $this->beef, 2, [], 0);
        $this->boxFries = $this->p4FixedLine(['combo_product_id' => $this->box], $this->fries, 1, [$this->loaded => '0.800'], 1);
        $this->boxDrink = $this->p4ChoiceLine(['combo_product_id' => $this->box], $this->drinks, 1, [$this->juice => '0.300'], [$this->water], 2);
        $this->party = $this->p4Product('Party drinks', '6.000', ['product_type' => 'combo']);
        $this->partyDrinks = $this->p4ChoiceLine(['combo_product_id' => $this->party], $this->drinks, 4, [$this->juice => '0.300'], [$this->water]);
        $this->duo = $this->p4Product('Duo', '3.000', ['product_type' => 'combo']);
        $this->p4FixedLine(['combo_product_id' => $this->duo], $this->beef, 1, [], 0);
        $this->p4FixedLine(['combo_product_id' => $this->duo], $this->fries, 1, [], 1);
        $this->meal = $this->p4Meal('meal', '1.200', [$this->burgers]);
        $this->mealFries = $this->p4FixedLine(['meal_id' => $this->meal], $this->fries, 1, [$this->loaded => '0.800'], 0);
        $this->mealDrink = $this->p4ChoiceLine(['meal_id' => $this->meal], $this->drinks, 1, [$this->juice => '0.300'], [], 1);
    }

    /** @param list<array<string, mixed>> $combo */
    private function qrLine(int $productId, array $combo = [], int $qty = 1, array $addonIds = [], ?int $mealId = null): array
    {
        return ['product_id' => $productId, 'qty' => $qty, 'addon_ids' => $addonIds, 'notes' => '', 'combo' => $combo]
            + ($mealId === null ? [] : ['meal_id' => $mealId]);
    }

    private function pick(int $lineId, int $productId, int $qty = 1, array $addonIds = []): array
    {
        return ['line_id' => $lineId, 'product_id' => $productId, 'qty' => $qty, 'addon_ids' => $addonIds, 'notes' => ''];
    }

    /** @param list<array<string, mixed>> $lines */
    private function quote(array $lines): TestResponse
    {
        return $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/quote', ['lines' => $lines]);
    }

    /** @return list<array{product_id: int|null, kind: string|null, line_id: int|null, qty: float, extra: float, allocated: int|null, total: float}> */
    private function rows(string $uuid): array
    {
        $order = Order::query()->where('uuid', $uuid)->sole();

        return OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->map(static fn (OrderItem $i): array => [
            'product_id' => $i->product_id !== null ? (int) $i->product_id : null, 'kind' => $i->combo_child_kind,
            'line_id' => $i->combo_line_id !== null ? (int) $i->combo_line_id : null, 'qty' => (float) $i->qty,
            'extra' => (float) $i->combo_extra_price, 'allocated' => $i->allocated_revenue_baisas !== null ? (int) $i->allocated_revenue_baisas : null,
            'total' => (float) $i->line_total,
        ])->all();
    }

    private function pay(string $token, array $order, int $amount): void
    {
        $at = now()->subMinute()->toIso8601String();
        $results = $this->p4Push($token, [
            $this->p4Event('order.create', $order),
            ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
                'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at,
                    'payments' => [['method' => 'cash', 'amount_baisas' => $amount, 'change_given_baisas' => 0]]]],
        ])->json('data.results');
        $this->assertSame(['processed', 'processed'], array_column($results, 'status'), (string) json_encode($results));
    }

    private function shelf(int $productId): float
    {
        return (float) DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', $productId)->value('stock_qty');
    }

    public function test_a_fixed_only_combo_adds_all_its_items_at_one_price(): void
    {
        // Nothing to choose: the client sends no items and every fixed item is served.
        $this->quote([$this->qrLine($this->duo)])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3000)
            ->assertJsonPath('data.quote.lines.0.components.0.kind', 'fixed');
        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/checkout', $this->p4QrCheckout([$this->qrLine($this->duo)]))
            ->assertStatus(201)->assertJsonPath('data.order.grand_total_baisas', 3000);
        $rows = $this->rows((string) DB::table('pos_orders')->value('uuid'));
        $this->assertSame([$this->duo, $this->beef, $this->fries], array_column($rows, 'product_id'));
        $this->assertSame([null, 'fixed', 'fixed'], array_column($rows, 'kind'));
        $this->assertSame([3.0, 0.0, 0.0], array_column($rows, 'total'));
        // Beef 2.000 : Fries 1.000 → 2.000 + 1.000.
        $this->assertSame([null, 2000, 1000], array_column($rows, 'allocated'));

        // The device config sends the lines: two fixed items, nothing to ask.
        $this->p4Device('mdev_combo_cfg');
        $duo = collect($this->withToken('mdev_combo_cfg')->getJson('/api/v1/device/config')->assertOk()->json('data.products'))->firstWhere('id', $this->duo);
        $this->assertSame([['fixed', $this->beef, 1], ['fixed', $this->fries, 1]],
            array_map(static fn (array $l): array => [$l['kind'], $l['product_id'], $l['quantity']], $duo['combo']['lines']));
        $this->assertArrayNotHasKey('slots', $duo['combo']);
    }

    public function test_a_choice_line_takes_pick_n_with_repeats_extra_prices_and_unticked_items(): void
    {
        // 3 Cola + 1 Juice (+0.300) = 6.300.
        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $this->cola, 3), $this->pick($this->partyDrinks, $this->juice)])])
            ->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 6300);
        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $this->cola, 2), $this->pick($this->partyDrinks, $this->cola, 2)])])
            ->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 6000);
        // Pick 4 means 4: three, five or none are refused (nothing is pre-picked).
        foreach ([[$this->pick($this->partyDrinks, $this->cola, 3)], [$this->pick($this->partyDrinks, $this->cola, 5)], []] as $picks) {
            $this->quote([$this->qrLine($this->party, $picks)])->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');
        }
        // The unticked Water and a product of another category are refused.
        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $this->cola, 3), $this->pick($this->partyDrinks, $this->water)])])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');
        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $this->cola, 3), $this->pick($this->partyDrinks, $this->fries)])])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');

        // The menu lists the category minus the unticked item, with the extra prices.
        $party = collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->firstWhere('id', $this->party);
        $line = $party['combo']['lines'][0];
        $this->assertSame(['choice', 4, 'Drink', $this->drinks], [$line['kind'], $line['pick_count'], $line['name'], $line['category_id']]);
        $this->assertSame([[$this->cola, 0], [$this->juice, 300]], array_map(static fn (array $i): array => [$i['product_id'], $i['extra_price_baisas']], $line['items']));
    }

    public function test_a_product_added_to_the_category_later_joins_the_choice_automatically(): void
    {
        $this->p4Device('mdev_combo_join');
        $cursor = now()->toIso8601String();
        $this->travel(5)->minutes();
        $lemonade = $this->p4Product('Lemonade', '0.900', ['category_id' => $this->drinks, 'created_at' => now(), 'updated_at' => now()]);

        $party = collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->firstWhere('id', $this->party);
        $this->assertContains($lemonade, array_column($party['combo']['lines'][0]['items'], 'product_id'));
        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $lemonade, 4)])])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 6000);
        // A device delta re-sends the combos with the new item, and the item itself.
        $delta = $this->withToken('mdev_combo_join')->getJson('/api/v1/device/config/delta?since='.urlencode($cursor))->assertOk()->json('data');
        $combo = collect($delta['products'])->firstWhere('id', $this->party);
        $this->assertContains($lemonade, array_column($combo['combo']['lines'][0]['items'], 'product_id'));
        $this->assertContains($lemonade, array_column($delta['products'], 'id'));
    }

    public function test_an_upgrade_swaps_the_product_adds_its_price_and_takes_the_upgrades_stock(): void
    {
        // Family box 5.000 + Loaded fries 0.800 + Juice 0.300 = 6.100.
        $picks = [$this->pick($this->boxFries, $this->loaded), $this->pick($this->boxDrink, $this->juice)];
        $this->quote([$this->qrLine($this->box, $picks)])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 6100)
            ->assertJsonPath('data.quote.lines.0.components.1.kind', 'upgrade')
            ->assertJsonPath('data.quote.lines.0.components.1.extra_price_baisas', 800);
        // Something that is not the fixed item nor one of its upgrades is refused.
        $this->quote([$this->qrLine($this->box, [$this->pick($this->boxFries, $this->cola), $this->pick($this->boxDrink, $this->juice)])])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');

        // A till sells two boxes with the upgrade: the loaded fries leave the shelf, the fries stay.
        $this->p4Device('mdev_combo_up');
        $wire = ['product_id' => $this->box, 'qty' => 2, 'unit_price_baisas' => 6100, 'line_total_baisas' => 12200, 'combo' => [
            ['line_id' => $this->boxBeef, 'kind' => 'fixed', 'product_id' => $this->beef, 'qty' => 2, 'extra_price_baisas' => 0],
            ['line_id' => $this->boxFries, 'kind' => 'upgrade', 'product_id' => $this->loaded, 'qty' => 1, 'extra_price_baisas' => 800],
            ['line_id' => $this->boxDrink, 'kind' => 'choice', 'product_id' => $this->juice, 'qty' => 1, 'extra_price_baisas' => 300],
        ]];
        $order = $this->p4Order([$wire]);
        $this->pay('mdev_combo_up', $order, 12200);
        $this->assertSame([20.0, 8.0], [$this->shelf($this->fries), $this->shelf($this->loaded)]);
        $rows = $this->rows($order['uuid']);
        $this->assertSame([null, 'fixed', 'upgrade', 'choice'], array_column($rows, 'kind'));
        $this->assertSame([0.0, 0.0, 0.8, 0.3], array_column($rows, 'extra'));
        $this->assertSame([2.0, 4.0, 2.0, 2.0], array_column($rows, 'qty'));
        $this->assertSame(12200, array_sum(array_column($rows, 'allocated')));
    }

    public function test_a_meal_on_beef_and_chicken_costs_the_mains_price_plus_the_meal_price(): void
    {
        $menu = collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertSame([$this->meal, $this->meal, null, null], [$menu[$this->beef]['meal_id'], $menu[$this->chicken]['meal_id'],
            $menu[$this->fries]['meal_id'], $menu[$this->box]['meal_id']]);

        // Beef 2.000 + 1.200 = 3.200; Chicken 1.800 + 1.200 = 3.000 (fries served as is, a free Cola).
        $this->quote([$this->qrLine($this->beef, [$this->pick($this->mealDrink, $this->cola)], 1, [], $this->meal)])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3200)
            ->assertJsonPath('data.quote.lines.0.display_name', 'Beef burger meal');
        $this->quote([$this->qrLine($this->chicken, [$this->pick($this->mealDrink, $this->cola)], 1, [], $this->meal)])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3000);
        // + Loaded fries 0.800 + Juice 0.300.
        $this->quote([$this->qrLine($this->beef, [$this->pick($this->mealFries, $this->loaded), $this->pick($this->mealDrink, $this->juice)], 1, [], $this->meal)])
            ->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 4300);
        // A meal on a non-main (Fries) or without its choice is refused.
        $this->quote([$this->qrLine($this->fries, [$this->pick($this->mealDrink, $this->cola)], 1, [], $this->meal)])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'combo_invalid');
        $this->quote([$this->qrLine($this->beef, [], 1, [], $this->meal)])->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');

        // Checkout writes the meal parent (no product) and the main as its first child.
        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/checkout', $this->p4QrCheckout([
            $this->qrLine($this->beef, [$this->pick($this->mealDrink, $this->cola)], 1, [], $this->meal),
        ]))->assertStatus(201)->assertJsonPath('data.order.grand_total_baisas', 3200);
        $parent = OrderItem::query()->whereNull('parent_order_item_id')->sole();
        $this->assertSame([null, $this->meal, 'Beef burger meal', 3.2], [$parent->product_id, (int) $parent->meal_id, $parent->product_name_snapshot, (float) $parent->line_total]);
        $rows = $this->rows((string) DB::table('pos_orders')->value('uuid'));
        $this->assertSame([null, $this->beef, $this->fries, $this->cola], array_column($rows, 'product_id'));
        $this->assertSame([null, 'main', 'fixed', 'choice'], array_column($rows, 'kind'));
        $this->assertSame([null, null, $this->mealFries, $this->mealDrink], array_column($rows, 'line_id'));
        // 3.200 by 2.000 : 1.000 : 1.000 → 1.600 + 0.800 + 0.800.
        $this->assertSame([null, 1600, 800, 800], array_column($rows, 'allocated'));
        // The parent's cooking time is its longest child (the beef burger's 12).
        $this->assertSame(12, (int) $parent->cooking_minutes);
    }

    public function test_the_profit_split_adds_up_exactly_with_the_last_item_taking_the_remainder(): void
    {
        // Family box paid 5.000; normal prices 2 × 2.000 + 1.000 + 1.000 = 6.000 →
        // per unit 1.667 + 1.667 + 0.833 + 0.833 (the beef child holds both burgers).
        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/checkout', $this->p4QrCheckout([
            $this->qrLine($this->box, [$this->pick($this->boxDrink, $this->cola)]),
        ]))->assertStatus(201)->assertJsonPath('data.order.grand_total_baisas', 5000);
        $rows = $this->rows((string) DB::table('pos_orders')->value('uuid'));
        $this->assertSame([null, 3334, 833, 833], array_column($rows, 'allocated'));
        $this->assertSame(5000, array_sum(array_column($rows, 'allocated')));

        // A device that sends its own split (adding up exactly) keeps it; one that does not add up is re-split.
        $this->p4Device('mdev_combo_split');
        $line = fn (array $shares): array => ['product_id' => $this->box, 'qty' => 1, 'unit_price_baisas' => 5000, 'line_total_baisas' => 5000, 'combo' => [
            ['line_id' => $this->boxBeef, 'kind' => 'fixed', 'product_id' => $this->beef, 'qty' => 2, 'extra_price_baisas' => 0, 'allocated_revenue_baisas' => $shares[0]],
            ['line_id' => $this->boxFries, 'kind' => 'fixed', 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0, 'allocated_revenue_baisas' => $shares[1]],
            ['line_id' => $this->boxDrink, 'kind' => 'choice', 'product_id' => $this->cola, 'qty' => 1, 'extra_price_baisas' => 0, 'allocated_revenue_baisas' => $shares[2]],
        ]];
        $kept = $this->p4Order([$line([3000, 1000, 1000])]);
        $resplit = $this->p4Order([$line([3000, 1000, 999])]);
        $results = $this->p4Push('mdev_combo_split', [$this->p4Event('order.create', $kept), $this->p4Event('order.create', $resplit)])->json('data.results');
        $this->assertSame([null, 3000, 1000, 1000], array_column($this->rows($kept['uuid']), 'allocated'));
        $this->assertSame([null, 3334, 833, 833], array_column($this->rows($resplit['uuid']), 'allocated'));
        // The pricing check flags a split that does not add up (never refused).
        $this->assertTrue($results[0]['result']['pricing_check']['match'], (string) json_encode($results[0]['result']['pricing_check']));
        $this->assertFalse($results[1]['result']['pricing_check']['match']);
        $this->assertSame('combo', $results[1]['result']['pricing_check']['failures'][0]['code']);
    }

    public function test_a_minus_remove_lowers_the_price_and_a_line_never_goes_below_0(): void
    {
        $noCheese = $this->p4Addons($this->beef, ['NO Cheese' => '-0.100'], ['name' => 'Remove', 'kind' => 'remove'])['NO Cheese'];
        $this->quote([$this->qrLine($this->beef, [], 1, [$noCheese])])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 1900);
        // Inside a meal the main's remove lowers the meal: 2.000 − 0.100 + 1.200 = 3.100.
        $this->quote([$this->qrLine($this->beef, [$this->pick($this->mealDrink, $this->cola)], 1, [$noCheese], $this->meal)])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3100);
        // A 0.050 item with a −0.100 remove costs 0, never below.
        $bite = $this->p4Product('Bite', '0.050');
        $noSauce = $this->p4Addons($bite, ['NO Sauce' => '-0.100'], ['name' => 'Remove', 'kind' => 'remove'])['NO Sauce'];
        $this->quote([$this->qrLine($bite, [], 2, [$noSauce])])->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 0)
            ->assertJsonPath('data.quote.lines.0.line_total_baisas', 0);
        // The menu sends the minus price as is.
        $groups = collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.addon_groups'));
        $this->assertSame(-100, collect($groups->firstWhere('name', 'Remove')['addons'])->firstWhere('id', $noCheese)['price_delta_baisas']);
    }

    public function test_a_prep_item_add_on_inside_a_meal_consumes_the_prep_items_raw_ingredients(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_products')->where('id', self::SHAWARMA)->update(['category_id' => $this->burgers]);
        // Extra garlic sauce: 30 ml of the prep item (200 garlic + 700 oil + 100 lemon per 1000 ml).
        $extra = $this->p4Addons(self::SHAWARMA, ['Extra sauce' => '0.200'])['Extra sauce'];
        DB::table('pos_addon_consumptions')->insert(['add_on_id' => $extra, 'ingredient_id' => self::SAUCE, 'direction' => 'add',
            'quantity' => '30', 'unit' => 'ml', 'created_at' => now(), 'updated_at' => now()]);
        $this->p4Device('mdev_combo_prep');
        $wire = ['product_id' => self::SHAWARMA, 'meal_id' => $this->meal, 'qty' => 1, 'unit_price_baisas' => 2900, 'line_total_baisas' => 2900,
            'addons' => [['add_on_id' => $extra, 'price_delta_baisas' => 200]], 'combo' => [
                ['line_id' => $this->mealFries, 'kind' => 'fixed', 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0],
                ['line_id' => $this->mealDrink, 'kind' => 'choice', 'product_id' => $this->cola, 'qty' => 1, 'extra_price_baisas' => 0],
            ]];
        $order = $this->p4Order([$wire]);
        $this->pay('mdev_combo_prep', $order, 2900);

        // Garlic: 5 direct + 6 in the recipe's 30 ml sauce + 6 in the extra 30 ml = 17; oil 21 + 21; lemon 3 + 3.
        $this->assertEqualsWithDelta([983.0, 958.0, 994.0], [$this->branchBalance(self::GARLIC), $this->branchBalance(self::OIL), $this->branchBalance(self::LEMON)], 0.001);
        $this->assertPrepHasNoStock();
        // The main child carries the add-on (the meal parent takes nothing from stock).
        $main = OrderItem::query()->where('combo_child_kind', 'main')->sole();
        $this->assertSame([self::SHAWARMA, 1], [(int) $main->product_id, $main->addons()->count()]);
        $this->assertSame(0, OrderItem::query()->whereNotNull('meal_id')->sole()->addons()->count());
        $this->assertSame(19.0, $this->shelf($this->fries));
    }

    public function test_another_merchants_products_categories_and_meals_are_never_used(): void
    {
        DB::table('pos_companies')->insertOrIgnore(['id' => 200, 'uuid' => (string) Str::uuid(), 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        // Another merchant's product placed in our Drinks category id, and their own category.
        $theirs = $this->p4Product('Their cola', '0.100', ['company_id' => 200, 'category_id' => $this->drinks]);
        $theirCategory = $this->p4Category('Their drinks', 200);
        $ownInTheirCategory = $this->p4Product('Stray', '0.100', ['category_id' => $theirCategory]);
        $bad = $this->p4ChoiceLine(['combo_product_id' => $this->party], $theirCategory, 1, [], [], 5);
        $theirMeal = $this->p4Meal('their meal', '0.100', [$this->burgers], [], ['company_id' => 200]);

        $menu = collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->keyBy('id');
        $lines = collect($menu[$this->party]['combo']['lines'])->keyBy('id');
        $this->assertNotContains($theirs, array_column($lines[$this->partyDrinks]['items'], 'product_id'));
        // A line naming their category is never served (nor our product filed under it).
        $this->assertFalse($lines->has($bad));
        $this->assertNotContains($ownInTheirCategory, $lines->flatMap(static fn (array $l): array => array_column($l['items'], 'product_id'))->all());
        $this->assertSame($this->meal, $menu[$this->beef]['meal_id']);

        $this->quote([$this->qrLine($this->party, [$this->pick($this->partyDrinks, $theirs, 4)])])->assertStatus(422);
        $this->quote([$this->qrLine($this->beef, [$this->pick($this->mealDrink, $this->cola)], 1, [], $theirMeal)])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'combo_invalid');

        // A device order naming their meal or their product inside a combo fails as a whole.
        $this->p4Device('mdev_combo_tenant');
        $results = $this->p4Push('mdev_combo_tenant', [
            $this->p4Event('order.create', $this->p4Order([['product_id' => $this->beef, 'meal_id' => $theirMeal, 'qty' => 1,
                'unit_price_baisas' => 2100, 'line_total_baisas' => 2100]])),
            $this->p4Event('order.create', $this->p4Order([['product_id' => $this->party, 'qty' => 1, 'unit_price_baisas' => 6000,
                'line_total_baisas' => 6000, 'combo' => [['line_id' => $this->partyDrinks, 'product_id' => $theirs, 'qty' => 4]]]])),
        ])->json('data.results');
        $this->assertSame(['failed', 'failed'], array_column($results, 'status'));
        $this->assertStringContainsString('meal(s) outside the device tenant', (string) json_encode($results[0]));
        $this->assertStringContainsString('product(s) outside the device tenant', (string) json_encode($results[1]));
        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_a_device_meal_order_is_written_resumed_in_the_wire_shape_and_checked(): void
    {
        $this->p4Device('mdev_combo_meal');
        $wire = ['product_id' => $this->beef, 'meal_id' => $this->meal, 'qty' => 2, 'unit_price_baisas' => 3200, 'line_total_baisas' => 6400,
            'notes' => 'Well done', 'addons' => [], 'combo' => [
                // The device may leave the fixed Fries out: it is served as is.
                ['line_id' => $this->mealDrink, 'kind' => 'choice', 'product_id' => $this->cola, 'qty' => 1, 'extra_price_baisas' => 0],
            ]];
        $order = $this->p4Order([$wire]);
        $result = $this->p4Push('mdev_combo_meal', [$this->p4Event('order.hold', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertTrue($result['result']['pricing_check']['match'] ?? true);
        $rows = $this->rows($order['uuid']);
        $this->assertSame([null, $this->beef, $this->fries, $this->cola], array_column($rows, 'product_id'));
        $this->assertSame([null, 'main', 'fixed', 'choice'], array_column($rows, 'kind'));
        $this->assertSame([2.0, 2.0, 2.0, 2.0], array_column($rows, 'qty'));
        $this->assertSame([null, 3200, 1600, 1600], array_column($rows, 'allocated'));
        $this->assertSame('Well done', OrderItem::query()->where('combo_child_kind', 'main')->value('notes'));

        // Resumed (held order) in the device wire shape: the main is the line's product.
        $held = collect($this->withToken('mdev_combo_meal')->getJson('/api/v1/device/orders/active')->assertOk()->json('data.orders'))
            ->firstWhere('uuid', $order['uuid']);
        $line = $held['items'][0];
        $this->assertSame([$this->beef, $this->meal, 'Beef burger meal', 'Well done', 3200], [$line['product_id'], $line['meal_id'],
            $line['product_name'], $line['notes'], $line['main_allocated_revenue_baisas']]);
        $this->assertSame([[$this->mealFries, 'fixed', $this->fries], [$this->mealDrink, 'choice', $this->cola]],
            array_map(static fn (array $c): array => [$c['line_id'], $c['kind'], $c['product_id']], $line['combo']));

        // A re-send of the same order keeps the children's copies (unchanged lines).
        $copies = OrderItem::query()->whereNotNull('parent_order_item_id')->orderBy('id')->pluck('recipe_snapshot_json')->all();
        $this->p4Push('mdev_combo_meal', [$this->p4Event('order.hold', $order)]);
        $this->assertSame(4, OrderItem::query()->count());
        $this->assertSame($copies, OrderItem::query()->whereNotNull('parent_order_item_id')->orderBy('id')->pluck('recipe_snapshot_json')->all());

        // The pricing check flags a wrong extra price or an item the line does not offer (never refused).
        $bad = $this->p4Order([array_replace($wire, ['combo' => [
            ['line_id' => $this->mealDrink, 'kind' => 'choice', 'product_id' => $this->juice, 'qty' => 1, 'extra_price_baisas' => 0],
        ]])]);
        $check = $this->p4Push('mdev_combo_meal', [$this->p4Event('order.create', $bad)])->json('data.results.0.result.pricing_check');
        $this->assertFalse($check['match']);
        $this->assertSame(['code' => 'combo', 'expected' => ['line_index' => 0, 'extra_price_baisas' => 300],
            'actual' => ['line_index' => 0, 'extra_price_baisas' => 0]], $check['failures'][0]);
        $water = $this->p4Order([array_replace($wire, ['combo' => [
            ['line_id' => $this->mealDrink, 'kind' => 'choice', 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0],
        ]])]);
        $this->assertFalse($this->p4Push('mdev_combo_meal', [$this->p4Event('order.create', $water)])->json('data.results.0.result.pricing_check.match'));
    }

    public function test_the_device_config_sends_combo_lines_and_every_meal(): void
    {
        $this->p4Device('mdev_combo_meals');
        $config = $this->withToken('mdev_combo_meals')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $box = collect($config['products'])->firstWhere('id', $this->box);
        [$beef, $fries, $drink] = $box['combo']['lines'];
        $this->assertSame(['id' => $this->boxBeef, 'kind' => 'fixed', 'sort_order' => 0, 'product_id' => $this->beef, 'quantity' => 2,
            'upgrades' => [], 'name' => null, 'name_ar' => null, 'category_id' => null, 'pick_count' => null, 'items' => []], $beef);
        $this->assertSame([['product_id' => $this->loaded, 'upgrade_price_baisas' => 800, 'sort_order' => 0]], $fries['upgrades']);
        $this->assertSame([[$this->cola, 0], [$this->juice, 300]], array_map(static fn (array $i): array => [$i['product_id'], $i['extra_price_baisas']], $drink['items']));
        $this->assertSame([], $box['addon_group_ids']);
        $this->assertSame(12, $box['cooking_minutes']);

        $this->assertCount(1, $config['meals']);
        $meal = $config['meals'][0];
        $this->assertSame([$this->meal, 'meal', 1200, [$this->burgers], [], [$this->beef, $this->chicken]],
            [$meal['id'], $meal['name'], $meal['meal_price_baisas'], $meal['categories'], $meal['excluded'], $meal['mains']]);
        $this->assertSame(['fixed', 'choice'], array_column($meal['lines'], 'kind'));

        // An unticked main, or a meal switched off, reaches the device on the next pull (every pull carries the meals).
        DB::table('pos_meal_excluded_products')->insert(['company_id' => 100, 'meal_id' => $this->meal, 'product_id' => $this->chicken, 'created_at' => now(), 'updated_at' => now()]);
        $delta = $this->withToken('mdev_combo_meals')->getJson('/api/v1/device/config/delta?since='.urlencode(now()->toIso8601String()))->assertOk()->json('data');
        $this->assertSame([[$this->chicken], [$this->beef]], [$delta['meals'][0]['excluded'], $delta['meals'][0]['mains']]);
        DB::table('pos_meals')->update(['status' => 'inactive']);
        $this->assertSame([], $this->withToken('mdev_combo_meals')->getJson('/api/v1/device/config')->assertOk()->json('data.meals'));
    }

    public function test_the_kitchen_ticket_and_the_bill_list_every_item_with_its_kind_and_price(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $line = ['product_id' => $this->box, 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'combo' => [
            ['line_id' => $this->boxFries, 'product_id' => $this->loaded, 'qty' => 1, 'addons' => []],
            ['line_id' => $this->boxDrink, 'product_id' => $this->juice, 'qty' => 1, 'addons' => [], 'notes' => 'No ice'],
        ]];
        $mealLine = ['product_id' => $this->chicken, 'meal_id' => $this->meal, 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'combo' => [
            ['line_id' => $this->mealDrink, 'product_id' => $this->cola, 'qty' => 1, 'addons' => []],
        ]];
        $payload = ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [$line, $mealLine]];
        $ack = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => $payload['submitted_at'], 'payload' => $payload,
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $ack['status'], (string) json_encode($ack));
        // 5.000 + 0.800 + 0.300, and 1.800 + 1.200.
        $this->assertSame(9100, $ack['result']['total_baisas']);

        $round = QrOrderRound::query()->findOrFail($ack['result']['round_id']);
        $box = $round->priced_lines[0];
        $this->assertSame([['fixed', 'Beef burger', 2, 0, null], ['upgrade', 'Loaded fries', 1, 800, null], ['choice', 'Juice', 1, 300, 'Drink']],
            array_map(static fn (array $c): array => [$c['kind'], $c['name'], $c['qty'], $c['extra_price_baisas'], $c['line_name']], $box['components']));
        // The beef was left out of the request (served as is): marked, so an idempotent replay compares without it.
        $this->assertSame([true, false, false], array_column($box['components'], 'filled'));
        $meal = $round->priced_lines[1];
        $this->assertSame(['Chicken burger meal', $this->meal, 1200, 3000], [$meal['display_name'], $meal['meal_id'], $meal['meal_price_baisas'], $meal['unit_price_baisas']]);
        $this->assertSame(['Fries', 'Cola'], array_column($meal['components'], 'name'));

        $ticket = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/kitchen/claim-print', ['ticket_key' => 'round:'.$round->id])
            ->assertSuccessful()->json('data');
        $this->assertSame(['Beef burger', 'Loaded fries', 'Juice'], array_column($ticket['priced_lines'][0]['components'], 'name'));
        $this->assertSame('No ice', $ticket['priced_lines'][0]['components'][2]['notes']);
        app('auth')->forgetGuards();
        $detail = $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/'.$seating->table_id.'/detail')->assertOk()->json('data');
        $this->assertSame(['fixed', 'upgrade', 'choice'], array_column($detail['rounds'][0]['priced_lines'][0]['components'], 'kind'));
        $this->assertSame('Chicken burger meal', $detail['rounds'][0]['priced_lines'][1]['display_name']);
        $this->assertSame([$this->chicken, $this->meal], [$detail['bill']['items'][1]['product_id'], $detail['bill']['items'][1]['meal_id']]);
    }
}
