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
 *   "Burger meal" 2.500: Main (is_main: Burger +0, Chicken burger +0.300),
 *   Side (Fries), Drink (Cola, Juice +0.300, Summer juice). No own time.
 *   "Chicken box" 3.000 (its own time 20): Main (is_main: Chicken burger),
 *   Drink (Cola +0.100) — sold out at the branch.
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
        DB::table('pos_combo_slots')->where('id', $this->meal['slots'][0])->update(['is_main' => true]);
        $this->box = $this->p4Combo('Chicken box', '3.000', [
            ['Main', 1, 1, [$this->chicken => '0.000']],
            ['Drink', 1, 1, [$this->cola => '0.100']],
        ], ['cooking_minutes' => 20]);
        DB::table('pos_combo_slots')->where('id', $this->box['slots'][0])->update(['is_main' => true]);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->box['id'],
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->noKetchup = $this->p4Addons($this->burger, ['NO Ketchup' => '0.000'], ['name' => 'Remove', 'kind' => 'remove'])['NO Ketchup'];
        DB::table('pos_addons')->where('id', $this->noKetchup)->update(['removes_ingredient_id' => 1]);
        $this->removeGroup = (int) DB::table('pos_addons')->where('id', $this->noKetchup)->value('add_on_group_id');
        $this->p4Addons($this->burger, ['Well done' => '0.000'], ['name' => 'Instructions', 'kind' => 'instructions']);
        $this->p4Addons($this->fries, ['Salt' => '0.000']);
        // Every catalogue row was last edited on 1 Sep: a delta moves only by dates.
        foreach (['pos_products', 'pos_combo_slots', 'pos_combo_slot_options', 'pos_addon_groups', 'pos_addons', 'pos_addon_group_products', 'pos_product_sold_out'] as $table) {
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

        $this->assertSame([true, false, false], array_column($products[$this->meal['id']]['combo']['slots'], 'is_main'));

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
        $this->assertIsArray($meal['combo']['slots']);
        foreach ($meal['combo']['slots'] as $slot) {
            $this->assertSame(['int', 'string', 'int', 'int', 'int', 'array'], [get_debug_type($slot['id']), get_debug_type($slot['name']),
                get_debug_type($slot['min']), get_debug_type($slot['max']), get_debug_type($slot['sort_order']), get_debug_type($slot['options'])]);
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
        $this->assertIsBool($meal['combo']['slots'][0]['is_main']);
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
        $this->assertSame([$this->pie], array_column($delta['products'], 'id'));
        $this->assertNotContains($this->soup, $delta['deleted']['products']);
        $this->assertNotContains($this->latte, $delta['deleted']['products']);

        // A cursor taken today moves nothing.
        $today = $this->withToken('mdev_rv_delta')
            ->getJson('/api/v1/device/config/delta?since='.urlencode(Carbon::parse('2026-10-06 08:00:00', 'UTC')->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame([], $today['products']);
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
        [$main, $side, $drink] = $meal['combo']['slots'];
        $this->assertSame([true, false, false], [$main['is_main'], $side['is_main'], $drink['is_main']]);
        // The ended Summer juice is dropped from the Drink slot.
        $this->assertSame([$this->cola, $this->juice], array_column($drink['options'], 'product_id'));
        $this->assertSame([12, 15], array_column($main['options'], 'cooking_minutes'));
        $this->assertSame([0, null], array_column($drink['options'], 'cooking_minutes'));
        // A combo without its own time shows its longest option; with one, its own.
        $this->assertSame(15, $meal['cooking_minutes']);
        $this->assertSame(20, $products[$this->box['id']]['cooking_minutes']);
        $this->assertSame(12, $products[$this->burger]['cooking_minutes']);

        // "Make it a meal?": the Burger meal for both mains (the sold-out Chicken box is not offered).
        $this->assertSame([[
            'combo_product_id' => $this->meal['id'], 'slot_id' => $main['id'], 'name' => 'Burger meal', 'name_ar' => null,
            'image_url' => null, 'price_from_baisas' => 2500,
        ]], $products[$this->burger]['meals']);
        $this->assertSame([2800], array_column($products[$this->chicken]['meals'], 'price_from_baisas'));
        $this->assertSame([], $products[$this->fries]['meals']);
        $this->assertSame([], $meal['meals']);

        $groups = collect($menu['addon_groups'])->keyBy('name');
        $this->assertSame(['remove', 'instructions'], [$groups['Remove']['kind'], $groups['Instructions']['kind']]);
    }

    public function test_meals_list_a_combo_only_while_it_is_on_the_menu_and_available(): void
    {
        $session = $this->p4QrSession();
        $mealsOf = fn (): array => array_column(collect($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'))
            ->firstWhere('id', $this->chicken)['meals'], 'combo_product_id');
        $this->assertSame([$this->meal['id']], $mealsOf());

        // Back in stock: the Chicken box is offered too, from 3.100 (its drink costs 0.100).
        DB::table('pos_product_sold_out')->delete();
        $this->assertSame([$this->meal['id'], $this->box['id']], $mealsOf());
        $box = collect(collect($this->p4QrGet($session, '/api/v1/public/qr/menu')->json('data.products'))->firstWhere('id', $this->chicken)['meals'])
            ->firstWhere('combo_product_id', $this->box['id']);
        $this->assertSame(3100, $box['price_from_baisas']);

        // A combo outside its dates or its daily hours is not offered; nor one without a main slot.
        DB::table('pos_products')->where('id', $this->box['id'])->update(['on_sale_until' => '2026-10-05']);
        DB::table('pos_products')->where('id', $this->meal['id'])->update(['available_from' => '18:00:00', 'available_until' => '22:00:00']);
        $this->assertSame([], $mealsOf());
        DB::table('pos_products')->where('id', $this->meal['id'])->update(['available_from' => null, 'available_until' => null]);
        DB::table('pos_combo_slots')->where('id', $this->meal['slots'][0])->update(['is_main' => false]);
        $this->assertSame([], $mealsOf());
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
            ['slot_id' => $main, 'product_id' => $this->burger, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
            ['slot_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
            ['slot_id' => $drink, 'product_id' => $this->summer, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
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
            ['slot_id' => $combo['slots'][0], 'product_id' => $chicken, 'qty' => 2, 'addon_ids' => [$cheese], 'notes' => ''],
            ['slot_id' => $combo['slots'][0], 'product_id' => $beef, 'qty' => 2, 'addon_ids' => [], 'notes' => ''],
        ]];

        $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/quote', ['lines' => [$line]])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3500);

        // The device wire of the same line passes the server's price check.
        $this->p4Device('mdev_rv_fixture');
        $wire = ['product_id' => $combo['id'], 'qty' => 1, 'unit_price_baisas' => 3500, 'line_total_baisas' => 3500, 'combo' => [
            ['slot_id' => $combo['slots'][0], 'product_id' => $chicken, 'qty' => 2, 'extra_price_baisas' => 300,
                'addons' => [['add_on_id' => $cheese, 'price_delta_baisas' => 200]]],
            ['slot_id' => $combo['slots'][0], 'product_id' => $beef, 'qty' => 2, 'extra_price_baisas' => 0],
        ]];
        $result = $this->p4Push('mdev_rv_fixture', [$this->p4Event('order.create', $this->p4Order([$wire]))])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertTrue($result['result']['pricing_check']['match'], (string) json_encode($result['result']['pricing_check']));
    }
}
