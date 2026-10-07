<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchCosts;

use App\Support\Catalogue\Allergens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH costs & allergens add-on, Part A (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester call 3): every payload a menu is drawn from carries the allergens —
 * the device config (till / handheld), the QR menu and the tablet menu: each
 * product's and meal's `allergens` + `may_contain`, each add-on option's
 * `allergens`, each combo line item's, and the 14 codes with their English
 * and Arabic names.
 *
 *   Ingredients (LaunchP3RecipeFixtures + more): bread → gluten; lemon juice
 *     → sulphites; Garlic sauce (prep) ticked eggs itself; sesame paste →
 *     sesame; Tahini dressing (prep of a prep) = sesame paste + Garlic sauce;
 *     cheese → milk.
 *   Shawarma = chicken, bread, Garlic sauce, garlic; "may contain" gluten
 *     (already contained) and sesame.
 *   Platter = chicken, Garlic sauce, Tahini dressing.
 *   Cookie (bought in, no recipe) contains gluten + milk, may contain peanuts.
 *   Cake has a component, a topper that contains soy.
 *   Combo "Shawarma deal": Shawarma (upgrade Platter) + a drink (Cola, Milkshake
 *   → milk; Celery juice unticked).
 *   Meal "meal": a Cookie.
 */
final class AllergenPayloadTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP6Fixtures;
    use RefreshDatabase;

    private const SESAME_PASTE = 8;

    private const TAHINI = 7;

    private const CHEESE = 9;

    private int $cookie;

    private int $topper;

    private int $deal;

    private int $meal;

    /** @var array<string, int> */
    private array $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(self::TAHINI, 'Tahini dressing', 'ml', '0', isPrep: true, yield: '1000'),
            $this->ingredientRow(self::SESAME_PASTE, 'Sesame paste', 'g', '0.003000'),
            $this->ingredientRow(self::CHEESE, 'Cheese', 'g', '0.005000'),
            // Another merchant's ingredient, ticked fish.
            $this->ingredientRow(50, 'Their anchovy', 'g', '0.010000', companyId: 200),
        ]);
        $this->prepRecipe(self::TAHINI, [[self::SESAME_PASTE, '300'], [self::SAUCE, '700']]);
        $this->productRecipe(self::PLATTER, [[self::TAHINI, '20', 'ml']]);
        // Bad data: a recipe line naming the other merchant's ingredient is never followed.
        $this->productRecipe(self::SHAWARMA, [[50, '5', 'g']]);
        $this->tag(self::BREAD, 'gluten');
        $this->tag(self::LEMON, 'sulphites');
        $this->tag(self::SAUCE, 'eggs');
        $this->tag(self::SESAME_PASTE, 'sesame');
        $this->tag(self::CHEESE, 'milk');
        $this->tag(50, 'fish', 200);

        $this->own(self::SHAWARMA, 'may_contain', ['gluten', 'sesame']);
        $this->cookie = $this->p4Product('Cookie', '0.500', ['stock_mode' => 'unit']);
        $this->own($this->cookie, 'contains', ['milk', 'gluten']);
        $this->own($this->cookie, 'may_contain', ['peanuts']);
        $this->topper = $this->p4Product('Cake topper', '0.100', ['is_internal' => true, 'stock_mode' => 'unit']);
        $this->own($this->topper, 'contains', ['soy']);
        DB::table('pos_product_components')->insert(['product_id' => $this->cake, 'component_product_id' => $this->topper, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // Another merchant's tag on our product is never read.
        DB::table('pos_product_allergens')->insert(['company_id' => 200, 'product_id' => $this->coffee, 'allergen' => 'lupin', 'kind' => 'contains', 'created_at' => now(), 'updated_at' => now()]);

        $drinks = $this->p4Category('Drinks');
        $this->p4Product('Cola', '0.500', ['category_id' => $drinks]);
        $shake = $this->p4Product('Milkshake', '1.000', ['category_id' => $drinks]);
        $this->own($shake, 'contains', ['milk']);
        $celery = $this->p4Product('Celery juice', '1.000', ['category_id' => $drinks]);
        $this->own($celery, 'contains', ['celery']);
        $this->deal = $this->p4Product('Shawarma deal', '3.000', ['product_type' => 'combo']);
        $this->p4FixedLine(['combo_product_id' => $this->deal], self::SHAWARMA, 1, [self::PLATTER => '1.000'], 0);
        $this->p4ChoiceLine(['combo_product_id' => $this->deal], $drinks, 1, [], [$celery], 1);
        $sweets = $this->p4Category('Sweets');
        $this->meal = $this->p4Meal('meal', '0.400', [$sweets]);
        $this->p4FixedLine(['meal_id' => $this->meal], $this->cookie, 1, [], 0);

        $this->options = $this->p4Addons(self::SHAWARMA, ['Extra cheese' => '0.200', 'Add sauce' => '0.100', 'Add a cookie' => '0.400', 'Ice' => '0']);
        DB::table('pos_addons')->where('id', $this->options['Extra cheese'])->update(['ingredient_id' => self::CHEESE, 'ingredient_qty' => 20, 'ingredient_unit' => 'g']);
        DB::table('pos_addon_consumptions')->insert([
            ['add_on_id' => $this->options['Add sauce'], 'ingredient_id' => self::SAUCE, 'direction' => 'add', 'quantity' => 20, 'unit' => 'ml', 'created_at' => now(), 'updated_at' => now()],
            // A REMOVE usage line adds nothing.
            ['add_on_id' => $this->options['Ice'], 'ingredient_id' => self::BREAD, 'direction' => 'remove', 'quantity' => 1, 'unit' => 'piece', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('pos_addons')->where('id', $this->options['Add a cookie'])->update(['linked_product_id' => $this->cookie]);
        $remove = $this->p4Addons(self::SHAWARMA, ['No bread' => '0'], ['kind' => 'remove']);
        DB::table('pos_addons')->where('id', $remove['No bread'])->update(['removes_ingredient_id' => self::BREAD]);
        $this->options += $remove;
    }

    private function tag(int $ingredientId, string $allergen, int $companyId = 100): void
    {
        DB::table('pos_ingredient_allergens')->insert(['company_id' => $companyId, 'ingredient_id' => $ingredientId, 'allergen' => $allergen, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param list<string> $allergens */
    private function own(int $productId, string $kind, array $allergens): void
    {
        foreach ($allergens as $allergen) {
            DB::table('pos_product_allergens')->insert(['company_id' => 100, 'product_id' => $productId, 'allergen' => $allergen, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<string, mixed> $row @return array{0: list<string>, 1: list<string>} */
    private function pair(array $row): array
    {
        return [$row['allergens'], $row['may_contain']];
    }

    /** The expectations every payload shares (keyed products, meals, add-on options). */
    private function assertWorkedOut(array $products, array $meals, array $options): void
    {
        $this->assertSame([['gluten', 'eggs', 'sulphites'], ['sesame']], $this->pair($products[self::SHAWARMA]), 'Shawarma: bread, the prep item and its lemon; may contain sesame, never gluten twice');
        $this->assertSame([['eggs', 'sesame', 'sulphites'], []], $this->pair($products[self::PLATTER]), 'Platter: a prep of a prep is followed down');
        $this->assertSame([['gluten', 'milk'], ['peanuts']], $this->pair($products[$this->cookie]), 'Cookie: ticked on the product');
        $this->assertSame([['soy'], []], $this->pair($products[$this->cake]), 'Cake: its component');
        $this->assertSame([[], []], $this->pair($products[$this->coffee]), 'Coffee: another merchant tag is never read');
        $this->assertSame([['gluten', 'eggs', 'milk', 'sesame', 'sulphites'], []], $this->pair($products[$this->deal]),
            'Combo: the fixed item, its upgrade and every drink offered — never the unticked one');
        $this->assertSame([['gluten', 'milk'], ['peanuts']], $this->pair($meals[$this->meal]));
        $this->assertSame(['milk'], $options[$this->options['Extra cheese']]['allergens']);
        $this->assertSame(['eggs', 'sulphites'], $options[$this->options['Add sauce']]['allergens']);
        $this->assertSame(['gluten', 'milk'], $options[$this->options['Add a cookie']]['allergens']);
        $this->assertSame([], $options[$this->options['Ice']]['allergens']);
        $this->assertSame([], $options[$this->options['No bread']]['allergens']);
    }

    private function assertCatalogue(array $list): void
    {
        $this->assertCount(14, $list);
        $this->assertSame(Allergens::CODES, array_column($list, 'code'));
        $this->assertSame(['code' => 'gluten', 'name' => 'Gluten', 'name_ar' => 'الغلوتين'], $list[0]);
        $this->assertSame(['code' => 'tree_nuts', 'name' => 'Tree nuts', 'name_ar' => 'المكسرات'], $list[7]);
        $this->assertSame(['code' => 'molluscs', 'name' => 'Molluscs', 'name_ar' => 'الرخويات'], $list[13]);
    }

    public function test_the_device_config_carries_every_products_meals_and_options_allergens(): void
    {
        $config = $this->p6As($this->till, 'GET', '/api/v1/device/config')->assertOk()->json('data');
        $options = collect($config['addon_groups'])->flatMap(static fn (array $g): array => $g['addons'])->keyBy('id')->all();
        $this->assertWorkedOut(collect($config['products'])->keyBy('id')->all(), collect($config['meals'])->keyBy('id')->all(), $options);
        $this->assertCatalogue($config['allergens']);

        // A tag added later reaches the devices with the product once the
        // portal touches it (the delta follows updated_at).
        $since = now()->toIso8601String();
        $this->travel(5)->seconds();
        $this->tag(self::GARLIC, 'mustard');
        DB::table('pos_products')->where('id', self::SHAWARMA)->update(['updated_at' => now()]);
        $delta = $this->p6As($this->handheld, 'GET', '/api/v1/device/config/delta?since='.urlencode($since))->assertOk()->json('data');
        $shawarma = collect($delta['products'])->firstWhere('id', self::SHAWARMA);
        $this->assertSame(['gluten', 'eggs', 'mustard', 'sulphites'], $shawarma['allergens']);
        $this->assertCatalogue($delta['allergens']);
    }

    public function test_the_qr_menu_and_the_tablet_menu_carry_them_on_products_items_meals_and_options(): void
    {
        $qr = $this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data');
        $tablet = $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/menu?order_type=quick')->assertOk()->json('data');
        foreach (['qr' => $qr, 'tablet' => $tablet] as $label => $menu) {
            $products = collect($menu['products'])->keyBy('id')->all();
            $options = collect($menu['addon_groups'])->flatMap(static fn (array $g): array => $g['addons'])->keyBy('id')->all();
            $this->assertWorkedOut($products, collect($menu['meals'])->keyBy('id')->all(), $options);
            $this->assertCatalogue($menu['allergens']);

            // Each item inside a combo line carries its own (the sheet can show a choice's).
            [$fixed, $choice] = $products[$this->deal]['combo']['lines'];
            $this->assertSame(['gluten', 'eggs', 'sulphites'], $fixed['product']['allergens'], $label);
            $this->assertSame(['eggs', 'sesame', 'sulphites'], $fixed['upgrades'][0]['allergens'], $label);
            $this->assertSame([['Cola', []], ['Milkshake', ['milk']]], array_map(static fn (array $i): array => [$i['name'], $i['allergens']], $choice['items']), $label);
        }
    }

    public function test_allergen_codes_are_the_fixed_fourteen_in_order_and_unknown_codes_are_dropped(): void
    {
        $this->assertSame(['gluten', 'milk', 'molluscs'], Allergens::normalise(['molluscs', 'milk', 'gluten', 'milk', 'nuts', 7, null]));
        $this->assertCount(14, Allergens::NAMES);
        foreach (Allergens::NAMES as $code => [$en, $ar]) {
            $this->assertContains($code, Allergens::CODES);
            $this->assertNotSame('', $en);
            $this->assertMatchesRegularExpression('/^[\x{0600}-\x{06FF} ]+$/u', $ar, $code);
        }
        $this->assertSame(['Peanuts', 'الفول السوداني'], Allergens::NAMES['peanuts']);
    }
}
