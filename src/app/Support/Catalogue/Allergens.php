<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester call 3) — the 14 allergens and how a dish's allergens are WORKED
 * OUT. The same file lives in pos_api and pos_merchant (identical logic, the
 * same golden fixture in both test suites).
 *
 * Tags (what a merchant ticks):
 *   pos_ingredient_allergens   on an ingredient or a prep item
 *   pos_product_allergens      on a product, kind 'contains' (a bought-in or
 *                              physical item has no recipe) or 'may_contain'
 *                              (traces, added by hand on a dish)
 *
 * Worked out (never stored):
 *   ingredient   its own tags; a prep item also everything its recipe
 *                lines bring, followed down every level
 *   product      contains = its own 'contains' tags ∪ every recipe line's
 *                ingredient ∪ every component product's contains ∪ (a combo)
 *                every item its lines can serve: the fixed products, their
 *                upgrades and every product a choice line offers (its
 *                category's active standard menu products, minus the
 *                unticked ones);
 *                may_contain = its own 'may_contain' tags ∪ the same parts'
 *                may_contain, minus what it contains
 *   meal         the union of its lines' items (the main adds its own)
 *   option       an add-on option that ADDS something: its ingredient, its
 *                linked product and its 'add' stock-usage lines (ingredient
 *                or product); a Remove or quick-instruction option has none
 *
 * Every list is in the fixed order of {@see CODES}. Every read is scoped to
 * one merchant; a reference to another merchant's row is ignored.
 */
final class Allergens
{
    public const CODES = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    /** code => [English, Arabic] */
    public const NAMES = [
        'gluten' => ['Gluten', 'الغلوتين'],
        'crustaceans' => ['Crustaceans', 'القشريات'],
        'eggs' => ['Eggs', 'البيض'],
        'fish' => ['Fish', 'الأسماك'],
        'peanuts' => ['Peanuts', 'الفول السوداني'],
        'soy' => ['Soy', 'الصويا'],
        'milk' => ['Milk', 'الحليب'],
        'tree_nuts' => ['Tree nuts', 'المكسرات'],
        'celery' => ['Celery', 'الكرفس'],
        'mustard' => ['Mustard', 'الخردل'],
        'sesame' => ['Sesame', 'السمسم'],
        'sulphites' => ['Sulphites', 'الكبريتيت'],
        'lupin' => ['Lupin', 'الترمس'],
        'molluscs' => ['Molluscs', 'الرخويات'],
    ];

    public const CONTAINS = 'contains';

    public const MAY_CONTAIN = 'may_contain';

    /** @var array<int, list<string>> ingredient id => own tags */
    private array $ingredientTags = [];

    /** @var array<int, list<int>> prep id => component ingredient ids */
    private array $prepLines = [];

    /** @var array<int, list<int>> product id => recipe ingredient ids */
    private array $recipes = [];

    /** @var array<int, list<int>> product id => component product ids */
    private array $components = [];

    /** @var array<int, array{contains: list<string>, may_contain: list<string>}> product id => own tags */
    private array $own = [];

    /** @var array<int, list<int>> combo product id => item product ids */
    private array $comboItems = [];

    /** @var array<int, list<int>> meal id => item product ids */
    private array $mealItems = [];

    /** @var array<int, array{kind: string, ingredients: list<int>, products: list<int>}> add-on id => what it adds */
    private array $addons = [];

    /** @var array<int, list<string>> */
    private array $ingredientMemo = [];

    /** @var array<int, array{contains: list<string>, may_contain: list<string>}> */
    private array $productMemo = [];

    /** @return list<array{code: string, name: string, name_ar: string}> the fixed list, for payloads and pickers */
    public static function catalogue(): array
    {
        return array_map(static fn (string $code): array => [
            'code' => $code,
            'name' => self::NAMES[$code][0],
            'name_ar' => self::NAMES[$code][1],
        ], self::CODES);
    }

    /**
     * Known codes only, each once, in the fixed order.
     *
     * @param  iterable<mixed>  $codes
     * @return list<string>
     */
    public static function normalise(iterable $codes): array
    {
        $set = [];
        foreach ($codes as $code) {
            if (is_string($code) && in_array($code, self::CODES, true)) {
                $set[$code] = true;
            }
        }

        return array_values(array_filter(self::CODES, static fn (string $code): bool => isset($set[$code])));
    }

    /** A fresh graph of one merchant's catalogue. */
    public static function load(int $companyId): self
    {
        $graph = new self;

        $ingredientIds = DB::table('pos_ingredients')->where('company_id', $companyId)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $ingredientSet = array_fill_keys($ingredientIds, true);
        foreach (DB::table('pos_ingredient_allergens')->where('company_id', $companyId)->get(['ingredient_id', 'allergen']) as $row) {
            if (isset($ingredientSet[(int) $row->ingredient_id])) {
                $graph->ingredientTags[(int) $row->ingredient_id][] = (string) $row->allergen;
            }
        }
        foreach (DB::table('pos_ingredient_recipes')->whereIn('prep_ingredient_id', $ingredientIds ?: [0])->get(['prep_ingredient_id', 'ingredient_id']) as $row) {
            if (isset($ingredientSet[(int) $row->ingredient_id])) {
                $graph->prepLines[(int) $row->prep_ingredient_id][] = (int) $row->ingredient_id;
            }
        }

        $products = DB::table('pos_products')->where('company_id', $companyId)
            ->get(['id', 'category_id', 'product_type', 'is_internal', 'status', 'deleted_at']);
        $productSet = $products->keyBy(static fn (object $p): int => (int) $p->id)->all();
        $productIds = array_keys($productSet);
        foreach (DB::table('pos_product_recipes')->whereIn('product_id', $productIds ?: [0])->get(['product_id', 'ingredient_id']) as $row) {
            if (isset($ingredientSet[(int) $row->ingredient_id])) {
                $graph->recipes[(int) $row->product_id][] = (int) $row->ingredient_id;
            }
        }
        foreach (DB::table('pos_product_components')->whereIn('product_id', $productIds ?: [0])->get(['product_id', 'component_product_id']) as $row) {
            if (isset($productSet[(int) $row->component_product_id])) {
                $graph->components[(int) $row->product_id][] = (int) $row->component_product_id;
            }
        }
        foreach (DB::table('pos_product_allergens')->where('company_id', $companyId)->get(['product_id', 'allergen', 'kind']) as $row) {
            if (isset($productSet[(int) $row->product_id]) && in_array($row->kind, [self::CONTAINS, self::MAY_CONTAIN], true)) {
                $graph->own[(int) $row->product_id][(string) $row->kind][] = (string) $row->allergen;
            }
        }

        // Combo and meal lines: the fixed products, their upgrades and what
        // each choice line offers (its category's active standard menu
        // products, live, minus the unticked ones).
        $offered = [];
        foreach ($products as $p) {
            if ($p->deleted_at === null && (string) $p->status === 'active' && (string) ($p->product_type ?? 'standard') === 'standard'
                && ! (bool) $p->is_internal && $p->category_id !== null) {
                $offered[(int) $p->category_id][] = (int) $p->id;
            }
        }
        $lines = DB::table('pos_combo_lines')->where('company_id', $companyId)->get(['id', 'combo_product_id', 'meal_id', 'kind', 'product_id', 'category_id']);
        $lineIds = $lines->pluck('id')->all();
        $upgrades = DB::table('pos_combo_line_upgrades')->whereIn('line_id', $lineIds ?: [0])->get(['line_id', 'product_id'])->groupBy('line_id');
        $excluded = DB::table('pos_combo_line_items')->whereIn('line_id', $lineIds ?: [0])->where('excluded', true)->get(['line_id', 'product_id'])->groupBy('line_id');
        foreach ($lines as $line) {
            $items = [];
            if ($line->kind === 'fixed' && $line->product_id !== null) {
                $items[] = (int) $line->product_id;
                foreach ($upgrades->get($line->id) ?? [] as $upgrade) {
                    $items[] = (int) $upgrade->product_id;
                }
            } elseif ($line->kind === 'choice' && $line->category_id !== null) {
                $out = collect($excluded->get($line->id) ?? [])->pluck('product_id')->map(static fn ($id): int => (int) $id)->all();
                $items = array_values(array_diff($offered[(int) $line->category_id] ?? [], $out));
            }
            $items = array_values(array_filter($items, static fn (int $id): bool => isset($productSet[$id])));
            if ($line->combo_product_id !== null) {
                $graph->comboItems[(int) $line->combo_product_id] = array_merge($graph->comboItems[(int) $line->combo_product_id] ?? [], $items);
            } elseif ($line->meal_id !== null) {
                $graph->mealItems[(int) $line->meal_id] = array_merge($graph->mealItems[(int) $line->meal_id] ?? [], $items);
            }
        }

        $addons = DB::table('pos_addons')->join('pos_addon_groups', 'pos_addon_groups.id', '=', 'pos_addons.add_on_group_id')
            ->where('pos_addons.company_id', $companyId)
            ->get(['pos_addons.id', 'pos_addons.ingredient_id', 'pos_addons.linked_product_id', 'pos_addon_groups.kind']);
        $usage = DB::table('pos_addon_consumptions')->whereIn('add_on_id', $addons->pluck('id')->all() ?: [0])
            ->where('direction', 'add')->get(['add_on_id', 'ingredient_id', 'component_product_id'])->groupBy('add_on_id');
        foreach ($addons as $addon) {
            $ingredients = [];
            $linked = [];
            if ($addon->ingredient_id !== null) {
                $ingredients[] = (int) $addon->ingredient_id;
            }
            if ($addon->linked_product_id !== null) {
                $linked[] = (int) $addon->linked_product_id;
            }
            foreach ($usage->get($addon->id) ?? [] as $line) {
                if ($line->ingredient_id !== null) {
                    $ingredients[] = (int) $line->ingredient_id;
                }
                if ($line->component_product_id !== null) {
                    $linked[] = (int) $line->component_product_id;
                }
            }
            $graph->addons[(int) $addon->id] = [
                'kind' => (string) ($addon->kind ?? 'extras'),
                'ingredients' => array_values(array_filter($ingredients, static fn (int $id): bool => isset($ingredientSet[$id]))),
                'products' => array_values(array_filter($linked, static fn (int $id): bool => isset($productSet[$id]))),
            ];
        }

        return $graph;
    }

    /** @return list<string> the allergens ticked on the ingredient row itself */
    public function ownIngredient(int $ingredientId): array
    {
        return self::normalise($this->ingredientTags[$ingredientId] ?? []);
    }

    /** @return array{contains: list<string>, may_contain: list<string>} the ticks set by hand on the product itself */
    public function ownProduct(int $productId): array
    {
        return [
            'contains' => self::normalise($this->own[$productId][self::CONTAINS] ?? []),
            'may_contain' => self::normalise($this->own[$productId][self::MAY_CONTAIN] ?? []),
        ];
    }

    /** @return list<string> an ingredient's allergens (a prep item: with its recipe's, every level) */
    public function ingredient(int $ingredientId): array
    {
        return $this->ingredientMemo[$ingredientId] ??= self::normalise($this->collectIngredient($ingredientId, []));
    }

    /** @return array{contains: list<string>, may_contain: list<string>} */
    public function product(int $productId): array
    {
        return $this->productMemo[$productId] ??= $this->collectProduct($productId, []);
    }

    /**
     * What the product contains that was WORKED OUT (recipe, prep items,
     * components, combo items) — the merchant cannot untick these.
     *
     * @return list<string>
     */
    public function derived(int $productId): array
    {
        $set = [];
        foreach ($this->recipes[$productId] ?? [] as $ingredientId) {
            $set = array_merge($set, $this->ingredient($ingredientId));
        }
        foreach (array_merge($this->components[$productId] ?? [], $this->comboItems[$productId] ?? []) as $part) {
            $set = array_merge($set, $this->product($part)['contains']);
        }

        return self::normalise($set);
    }

    /** @return array{contains: list<string>, may_contain: list<string>} the union of a meal's line items */
    public function meal(int $mealId): array
    {
        return $this->union($this->mealItems[$mealId] ?? [], [], []);
    }

    /** @return list<string> what an add-on option adds (none for a Remove or quick-instruction option) */
    public function addon(int $addonId): array
    {
        $addon = $this->addons[$addonId] ?? null;
        if ($addon === null || in_array($addon['kind'], ['remove', 'instructions'], true)) {
            return [];
        }
        $set = [];
        foreach ($addon['ingredients'] as $ingredientId) {
            $set = array_merge($set, $this->ingredient($ingredientId));
        }
        foreach ($addon['products'] as $productId) {
            $set = array_merge($set, $this->product($productId)['contains']);
        }

        return self::normalise($set);
    }

    /**
     * @param  list<int>  $visiting
     * @return list<string>
     */
    private function collectIngredient(int $ingredientId, array $visiting): array
    {
        if (in_array($ingredientId, $visiting, true)) {
            return [];
        }
        $set = $this->ingredientTags[$ingredientId] ?? [];
        foreach ($this->prepLines[$ingredientId] ?? [] as $component) {
            $set = array_merge($set, $this->collectIngredient($component, [...$visiting, $ingredientId]));
        }

        return $set;
    }

    /**
     * @param  list<int>  $visiting
     * @return array{contains: list<string>, may_contain: list<string>}
     */
    private function collectProduct(int $productId, array $visiting): array
    {
        if (in_array($productId, $visiting, true)) {
            return ['contains' => [], 'may_contain' => []];
        }
        $contains = $this->own[$productId][self::CONTAINS] ?? [];
        foreach ($this->recipes[$productId] ?? [] as $ingredientId) {
            $contains = array_merge($contains, $this->ingredient($ingredientId));
        }

        return $this->union(
            array_merge($this->components[$productId] ?? [], $this->comboItems[$productId] ?? []),
            $contains,
            $this->own[$productId][self::MAY_CONTAIN] ?? [],
            [...$visiting, $productId],
        );
    }

    /**
     * @param  list<int>  $parts
     * @param  list<string>  $contains
     * @param  list<string>  $mayContain
     * @param  list<int>  $visiting
     * @return array{contains: list<string>, may_contain: list<string>}
     */
    private function union(array $parts, array $contains, array $mayContain, array $visiting = []): array
    {
        foreach ($parts as $part) {
            $of = in_array($part, $visiting, true) ? ['contains' => [], 'may_contain' => []]
                : ($visiting === [] ? $this->product($part) : $this->collectProduct($part, $visiting));
            $contains = array_merge($contains, $of['contains']);
            $mayContain = array_merge($mayContain, $of['may_contain']);
        }
        $contains = self::normalise($contains);

        return [
            'contains' => $contains,
            'may_contain' => array_values(array_diff(self::normalise($mayContain), $contains)),
        ];
    }
}
