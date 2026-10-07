<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchReview;

use App\Actions\Qr\LoadQrPricingInputAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH review add-on — the menu on the server (work order §3.2, §3.3,
 * §4.2; owner decisions D9–D12; tester calls 13–17).
 *
 * Today is Tuesday 6 Oct 2026 in Muscat (09:00 UTC = 13:00 local).
 *
 *   Burger 2.000 (cooking 12 min), Chicken burger 2.200 (15 min), Fries
 *   0.800 (4 min), Cola 0.500 (0 min), Juice 0.900 (no time).
 *   Pumpkin latte: on sale 1–31 Oct. Winter soup: from 1 Nov. Summer
 *   juice: until 5 Oct (ended yesterday). Autumn pie: from today.
 *   "Burger meal" 2.500: Main (pick 1 of the Main category: Burger +0,
 *   Chicken burger +0.300), Side (fixed: Fries), Drink (pick 1: Cola,
 *   Juice +0.300, Summer juice). No own time.
 *   "Chicken box" 3.000 (its own time 20): Main (fixed: Chicken burger),
 *   Drink (fixed: Cola) — sold out at the branch.
 *   LAUNCH combo add-on: the old "main slot" meals became a MEAL "meal"
 *   (+1.200) on the Main category ("Make it a meal?" for both burgers).
 *   Burger's own "Remove" group (kind remove: NO Ketchup → ketchup) and an
 *   "Instructions" group (kind instructions: Well done).
 */
final class MenuReviewTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burger;

    private int $chicken;

    private int $fries;

    private int $cola;

    private int $juice;

    private int $latte;

    private int $soup;

    private int $summer;

    private int $pie;

    /** @var array{id: int, slots: list<int>} */
    private array $meal;

    /** @var array{id: int, slots: list<int>} */
    private array $box;

    private int $noKetchup;

    private int $mealId;

    private int $removeGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $t = ['created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ketchup', 'unit' => 'g', 'status' => 'active',
                'piece_unit_label' => 'bottle', 'units_per_piece' => '1.5'] + $t,
        ]);
        $this->burger = $this->p4Product('Burger', '2.000', ['cooking_minutes' => 12]);
        $this->chicken = $this->p4Product('Chicken burger', '2.200', ['cooking_minutes' => 15]);
        $this->fries = $this->p4Product('Fries', '0.800', ['cooking_minutes' => 4]);
        $this->cola = $this->p4Product('Cola', '0.500', ['cooking_minutes' => 0]);
        $this->juice = $this->p4Product('Juice', '0.900');
        $this->latte = $this->p4Product('Pumpkin latte', '1.800', ['on_sale_from' => '2026-10-01', 'on_sale_until' => '2026-10-31']);
        $this->soup = $this->p4Product('Winter soup', '1.500', ['on_sale_from' => '2026-11-01']);
        $this->summer = $this->p4Product('Summer juice', '1.000', ['on_sale_until' => '2026-10-05']);
        $this->pie = $this->p4Product('Autumn pie', '1.200', ['on_sale_from' => '2026-10-06']);
        $this->meal = $this->p4Combo('Burger meal', '2.500', [
            ['Main', 1, 1, [$this->burger => '0.000', $this->chicken => '0.300']],
            ['Side', 1, 1, [$this->fries => '0.000']],
            ['Drink', 1, 1, [$this->cola => '0.000', $this->juice => '0.300', $this->summer => '0.000']],
        ]);
        $this->box = $this->p4Combo('Chicken box', '3.000', [
            ['Main', 1, 1, [$this->chicken => '0.000']],
            ['Drink', 1, 1, [$this->cola => '0.100']],
        ], ['cooking_minutes' => 20]);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->box['id'],
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->noKetchup = $this->p4Addons($this->burger, ['NO Ketchup' => '0.000'], ['name' => 'Remove', 'kind' => 'remove'])['NO Ketchup'];
        DB::table('pos_addons')->where('id', $this->noKetchup)->update(['removes_ingredient_id' => 1]);
        $this->removeGroup = (int) DB::table('pos_addons')->where('id', $this->noKetchup)->value('add_on_group_id');
        $this->p4Addons($this->burger, ['Well done' => '0.000'], ['name' => 'Instructions', 'kind' => 'instructions']);
        $this->p4Addons($this->fries, ['Salt' => '0.000']);
        // Every catalogue row was last edited on 1 Sep: a delta moves only by dates.
        $this->mealId = $this->p4Meal('meal', '1.200', [(int) DB::table('pos_products')->where('id', $this->burger)->value('category_id')]);
        foreach (['pos_products', 'pos_combo_lines', 'pos_combo_line_items', 'pos_meals', 'pos_addon_groups', 'pos_addons', 'pos_addon_group_products', 'pos_product_sold_out'] as $table) {
            DB::table($table)->update(['updated_at' => '2026-09-01 08:00:00']);
        }
    }

    /** @return array<string, mixed> */
    private function config(string $token): array
    {
        return $this->withToken($token)->getJson('/api/v1/device/config')->assertOk()->json('data');
    }

    public function test_the_device_config_adds_dates_cooking_time_main_slots_kinds_and_removals_and_leaves_out_off_dates_products(): void
    {
        $this->p4Device('mdev_rv_cfg');
        $config = $this->config('mdev_rv_cfg');
        $products = collect($config['products'])->keyBy('id');

        // Out-of-range products are not sent; today's first day and in-range ones are.
        $this->assertFalse($products->has($this->soup));
        $this->assertFalse($products->has($this->summer));
        $this->assertTrue($products->has($this->pie));
        $this->assertSame(['2026-10-01', '2026-10-31', null], [$products[$this->latte]['on_sale_from'], $products[$this->latte]['on_sale_until'], $products[$this->latte]['cooking_minutes']]);
        $this->assertSame([null, null, 12], [$products[$this->burger]['on_sale_from'], $products[$this->burger]['on_sale_until'], $products[$this->burger]['cooking_minutes']]);
        $this->assertSame(0, $products[$this->cola]['cooking_minutes']);
        $this->assertSame(20, $products[$this->box['id']]['cooking_minutes']);

        // LAUNCH combo add-on — combos are lines; meals ride on their own.
        $this->assertSame(['choice', 'fixed', 'choice'], array_column($products[$this->meal['id']]['combo']['lines'], 'kind'));
        $this->assertSame([[$this->burger, $this->chicken], [], [$this->cola, $this->juice]],
            array_map(static fn (array $line): array => array_column($line['items'], 'product_id'), $products[$this->meal['id']]['combo']['lines']));
        $this->assertSame([$this->mealId], array_column($config['meals'], 'id'));
        $this->assertSame([$this->burger, $this->chicken], $config['meals'][0]['mains']);

        $groups = collect($config['addon_groups'])->keyBy('name');
        $this->assertSame(['remove', 'instructions', 'extras'], [$groups['Remove']['kind'], $groups['Instructions']['kind'], $groups['Extras '.$this->fries]['kind']]);
        $this->assertSame($this->removeGroup, $groups['Remove']['id']);
        $this->assertSame(1, $groups['Remove']['addons'][0]['removes_ingredient_id']);
        $this->assertNull($groups['Instructions']['addons'][0]['removes_ingredient_id']);
    }

    public function test_old_app_config_compatibility_no_existing_key_changes_type_and_new_keys_are_additive(): void
    {
        $this->p4Device('mdev_rv_types');
        $config = $this->config('mdev_rv_types');
        $burger = collect($config['products'])->firstWhere('id', $this->burger);
        $meal = collect($config['products'])->firstWhere('id', $this->meal['id']);
        $ketchup = collect($config['ingredients'])->firstWhere('id', 1);
        $group = collect($config['addon_groups'])->firstWhere('name', 'Remove');

        // The keys today's till and handheld parse keep their JSON types.
        $this->assertIsInt($burger['id']);
        $this->assertIsString($burger['uuid']);
        $this->assertIsInt($burger['base_price_baisas']);
        $this->assertNull($burger['available_from']);
        $this->assertIsArray($burger['addon_group_ids']);
        $this->assertContainsOnlyInt($burger['addon_group_ids']);
        $this->assertIsArray($burger['recipe']);
        $this->assertIsBool($burger['sold_out']);
        $this->assertSame('standard', $burger['product_type']);
        // LAUNCH combo add-on — the combo payload is `combo.lines` (all clients move together).
        $this->assertIsArray($meal['combo']['lines']);
        foreach ($meal['combo']['lines'] as $line) {
            $this->assertSame(['int', 'string', 'int', 'array', 'array'], [get_debug_type($line['id']), get_debug_type($line['kind']),
                get_debug_type($line['sort_order']), get_debug_type($line['upgrades']), get_debug_type($line['items'])]);
        }
        $this->assertSame('bottle', $ketchup['piece_unit_label']);
        $this->assertIsFloat($ketchup['units_per_piece']);
        $this->assertSame(1.5, $ketchup['units_per_piece']);
        $this->assertIsBool($ketchup['allow_fractional_pieces']);
        $this->assertIsString($group['selection_mode']);
        $this->assertIsArray($group['addons']);
        $this->assertIsInt($group['addons'][0]['price_delta_baisas']);
        // No ingredient key is added (no containers are sent to devices).
        $this->assertSame([], array_values(array_intersect(array_keys($ketchup), ['count_container_id', 'sku', 'containers'])));

        // The new keys are additive scalars: dates as strings or null, the rest int / bool / string.
        $this->assertArrayHasKey('on_sale_from', $burger);
        $this->assertArrayHasKey('on_sale_until', $burger);
        $this->assertIsInt($burger['cooking_minutes']);
        $this->assertIsInt($meal['combo']['lines'][0]['pick_count']);
        $this->assertIsString($group['kind']);
        $this->assertIsInt($group['addons'][0]['removes_ingredient_id']);
    }

    public function test_a_delta_purges_a_product_whose_last_day_has_passed_and_sends_one_starting_today_with_no_row_edit(): void
    {
        $this->p4Device('mdev_rv_delta');
        // The device last synced yesterday at 13:00 Muscat; nothing was edited since.
        $delta = $this->withToken('mdev_rv_delta')
            ->getJson('/api/v1/device/config/delta?since='.urlencode(Carbon::parse('2026-10-05 09:00:00', 'UTC')->toIso8601String()))
            ->assertOk()->json('data');

        $this->assertContains($this->summer, $delta['deleted']['products']);
        // LAUNCH combo add-on — every combo is re-sent on every pull (its choice items follow its categories).
        $standard = static fn (array $products): array => array_column(array_filter($products, static fn (array $p): bool => $p['product_type'] === 'standard'), 'id');
        $this->assertSame([$this->pie], $standard($delta['products']));
        $this->assertEqualsCanonicalizing([$this->meal['id'], $this->box['id']], array_column(array_filter($delta['products'], static fn (array $p): bool => $p['product_type'] === 'combo'), 'id'));
        $this->assertNotContains($this->soup, $delta['deleted']['products']);
        $this->assertNotContains($this->latte, $delta['deleted']['products']);

        // A cursor taken today moves nothing.
        $today = $this->withToken('mdev_rv_delta')
            ->getJson('/api/v1/device/config/delta?since='.urlencode(Carbon::parse('2026-10-06 08:00:00', 'UTC')->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame([], $standard($today['products']));
        $this->assertSame([], $today['deleted']['products']);
    }

    public function test_a_paid_device_sale_of_an_ended_limited_product_is_accepted(): void
    {
        $this->p4Device('mdev_rv_sale');
        $this->assertFalse(collect($this->config('mdev_rv_sale')['products'])->contains('id', $this->summer));

        // An offline till still had it on its menu: the sale is never refused.
        $order = $this->p4Order([['product_id' => $this->summer, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]]);
        $at = now()->subMinute()->toIso8601String();
        $results = $this->p4Push('mdev_rv_sale', [
            $this->p4Event('order.create', $order),
            ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
                'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at,
                    'payments' => [['method' => 'cash', 'amount_baisas' => 1000, 'change_given_baisas' => 0]]]],
        ])->json('data.results');
        $this->assertSame(['processed', 'processed'], array_column($results, 'status'), (string) json_encode($results));
        $this->assertSame('paid', DB::table('pos_orders')->where('uuid', $order['uuid'])->value('status'));
    }

    public function test_the_qr_menu_omits_off_dates_products_and_options_and_carries_main_slots_cooking_time_kinds_and_meals(): void
    {
        $menu = $this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data');
        $products = collect($menu['products'])->keyBy('id');

        $this->assertFalse($products->has($this->soup));
        $this->assertFalse($products->has($this->summer));
        $this->assertTrue($products->has($this->latte));
        $this->assertTrue($products->has($this->pie));

        $meal = $products[$this->meal['id']];
        [$main, $side, $drink] = $meal['combo']['lines'];
        $this->assertSame(['choice', 'fixed', 'choice'], [$main['kind'], $side['kind'], $drink['kind']]);
        $this->assertSame($this->fries, $side['product']['product_id']);
        // The ended Summer juice is dropped from the Drink line.
        $this->assertSame([$this->cola, $this->juice], array_column($drink['items'], 'product_id'));
        $this->assertSame([0, 300], array_column($drink['items'], 'extra_price_baisas'));
        $this->assertSame([12, 15], array_column($main['items'], 'cooking_minutes'));
        $this->assertSame([0, null], array_column($drink['items'], 'cooking_minutes'));
        // A combo without its own time shows its longest option; with one, its own.
        $this->assertSame(15, $meal['cooking_minutes']);
        $this->assertSame(20, $products[$this->box['id']]['cooking_minutes']);
        $this->assertSame(12, $products[$this->burger]['cooking_minutes']);

        // "Make it a meal? +1.200" on both burgers (the meal's category), never on fries or a combo.
        $this->assertSame([$this->mealId, $this->mealId, null, null], [$products[$this->burger]['meal_id'], $products[$this->chicken]['meal_id'],
            $products[$this->fries]['meal_id'], $meal['meal_id']]);
        $this->assertSame([['id' => $this->mealId, 'meal_price_baisas' => 1200, 'available' => true]],
            array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'meal_price_baisas', 'available'])), $menu['meals']));

        $groups = collect($menu['addon_groups'])->keyBy('name');
        $this->assertSame(['remove', 'instructions'], [$groups['Remove']['kind'], $groups['Instructions']['kind']]);
    }

    public function test_meals_are_offered_only_while_active_on_sale_and_complete(): void
    {
        $session = $this->p4QrSession();
        $mealOf = fn (): mixed => collect($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'))
            ->firstWhere('id', $this->chicken)['meal_id'];
        $this->assertSame($this->mealId, $mealOf());

        // LAUNCH combo add-on — a fixed item of the meal sold out: no offer.
        $this->p4FixedLine(['meal_id' => $this->mealId], $this->fries);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->fries,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($mealOf());
        DB::table('pos_product_sold_out')->where('product_id', $this->fries)->delete();
        $this->assertSame($this->mealId, $mealOf());

        // Outside its dates, switched off, unticked or deleted: no offer.
        DB::table('pos_meals')->where('id', $this->mealId)->update(['on_sale_until' => '2026-10-05']);
        $this->assertNull($mealOf());
        DB::table('pos_meals')->where('id', $this->mealId)->update(['on_sale_until' => null, 'status' => 'inactive']);
        $this->assertNull($mealOf());
        DB::table('pos_meals')->where('id', $this->mealId)->update(['status' => 'active']);
        DB::table('pos_meal_excluded_products')->insert(['company_id' => 100, 'meal_id' => $this->mealId, 'product_id' => $this->chicken,
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($mealOf());
        DB::table('pos_meal_excluded_products')->delete();
        DB::table('pos_meals')->where('id', $this->mealId)->update(['deleted_at' => now()]);
        $this->assertNull($mealOf());
    }

    public function test_the_qr_pricer_refuses_an_off_dates_product_and_a_staff_round_holds_it_as_outside_dates(): void
    {
        $session = $this->p4QrSession();
        $line = ['product_id' => $this->soup, 'qty' => 1, 'addon_ids' => [], 'notes' => ''];
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$line]])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [['product_id' => $this->latte, 'qty' => 1, 'addon_ids' => [], 'notes' => '']]])
            ->assertOk();

        // An ended combo option inside an always-on combo is refused too.
        [$main, $side, $drink] = $this->meal['slots'];
        $meal = ['product_id' => $this->meal['id'], 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [
            ['line_id' => $main, 'product_id' => $this->burger, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
            ['line_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
            ['line_id' => $drink, 'product_id' => $this->summer, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
        ]];
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$meal]])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');

        $classified = app(LoadQrPricingInputAction::class)->classify(100, 10, [$line, ['product_id' => $this->burger, 'qty' => 1, 'addon_ids' => [], 'notes' => '']], null, true);
        $this->assertSame([['line_index' => 0, 'product_id' => $this->soup, 'addon_id' => null, 'reason' => 'outside_dates']], $classified['held']);
        $this->assertCount(1, $classified['priceable']);
    }

    public function test_the_combo_price_fixture_two_chicken_with_cheese_and_two_beef_cost_3_500(): void
    {
        // Menu audit §8.2 (shared with pos_web, the till and the handheld).
        $chicken = $this->p4Product('Chicken piece', '1.000');
        $beef = $this->p4Product('Beef piece', '1.000');
        $cheese = $this->p4Addons($chicken, ['Cheese' => '0.200'])['Cheese'];
        $combo = $this->p4Combo('Burgers, pick 4', '2.500', [['Burgers', 4, 4, [$chicken => '0.300', $beef => '0.000']]]);
        $line = ['product_id' => $combo['id'], 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [
            ['line_id' => $combo['slots'][0], 'product_id' => $chicken, 'qty' => 2, 'addon_ids' => [$cheese], 'notes' => ''],
            ['line_id' => $combo['slots'][0], 'product_id' => $beef, 'qty' => 2, 'addon_ids' => [], 'notes' => ''],
        ]];

        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/quote', ['lines' => [$line]])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3500);

        // The device wire of the same line passes the server's price check.
        $this->p4Device('mdev_rv_fixture');
        $wire = ['product_id' => $combo['id'], 'qty' => 1, 'unit_price_baisas' => 3500, 'line_total_baisas' => 3500, 'combo' => [
            ['line_id' => $combo['slots'][0], 'product_id' => $chicken, 'qty' => 2, 'extra_price_baisas' => 300,
                'addons' => [['add_on_id' => $cheese, 'price_delta_baisas' => 200]]],
            ['line_id' => $combo['slots'][0], 'product_id' => $beef, 'qty' => 2, 'extra_price_baisas' => 0],
        ]];
        $result = $this->p4Push('mdev_rv_fixture', [$this->p4Event('order.create', $this->p4Order([$wire]))])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertTrue($result['result']['pricing_check']['match'], (string) json_encode($result['result']['pricing_check']));
    }
}
