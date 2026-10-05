<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BusinessClock;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Catalogue\CookingTime;
use App\Support\Catalogue\SaleDates;
use App\Support\Money;
use App\Support\Pricing\CompanyTaxPolicy;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the customer branch menu without the device catalogue's delta/purge semantics.
 *
 * LAUNCH-P4:
 *  - only active products (L4: an inactive product is left out, not
 *    greyed), in-store ones shown on the QR menu (channels), sold at this
 *    branch by its branch scope; a product switched off here stays greyed;
 *  - a hand-set "sold out" product is shown, greyed and not orderable
 *    (`sold_out: true`, unavailable_reason 'sold_out');
 *  - a combo carries `combo.slots[].options[]` with each chosen item's
 *    name, photo, extra price, availability and add-on groups (the item's
 *    own groups are in `addon_groups`); a combo takes no add-ons itself;
 *  - `tax` says whether menu prices include VAT; windows use the
 *    merchant's wall clock (H9).
 */
final class BuildQrBranchMenuAction
{
    public function __construct(
        private readonly QrBranchProductQuery $products,
        private readonly ResolveQrAddOnAvailabilityAction $addonAvailability,
    ) {}

    /**
     * @return array{categories: list<array<string, mixed>>, products: list<array<string, mixed>>, addon_groups: list<array<string, mixed>>}
     */
    public function handle(int $companyId, int $branchId, ?DateTimeInterface $at = null): array
    {
        $at = BusinessClock::local($at);
        // LAUNCH review add-on — a product or combo outside its limited-time
        // dates is left out (not greyed), and so is such a combo option.
        $today = SaleDates::day($at);
        $products = SaleDates::onSale($this->products->forBranch($companyId, $branchId)->where('status', 'active'), $today)
            ->orderBy('display_order')->orderBy('id')->get();

        // Combo slots and their options (the chosen items need not be on the
        // menu themselves: a side sold only inside a meal).
        $comboIds = $products->filter(static fn (Product $p): bool => $p->isCombo())->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $slots = DB::table('pos_combo_slots')->whereIn('combo_product_id', $comboIds === [] ? [0] : $comboIds)
            ->orderBy('sort_order')->orderBy('id')->get();
        $options = DB::table('pos_combo_slot_options')->whereIn('slot_id', $slots->pluck('id')->all() ?: [0])
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('slot_id');
        $optionIds = $options->flatten(1)->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $optionProducts = SaleDates::onSale($this->products->soldByBranch($companyId, $branchId)->where('is_internal', false)
            ->where('product_type', '<>', Product::TYPE_COMBO)
            ->whereIn('pos_products.id', $optionIds === [] ? [0] : $optionIds), $today)->get()->keyBy('id');

        $groupProducts = $products->reject(static fn (Product $p): bool => $p->isCombo())
            ->keyBy('id')->union($optionProducts);
        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $allIds = array_values(array_unique(array_merge($productIds, $optionIds)));
        $groupProductIds = $groupProducts->keys()->map(static fn ($id): int => (int) $id)->all();
        $categoryIds = $products->pluck('category_id')->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $groupCategoryIds = $groupProducts->pluck('category_id')->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $branchProducts = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $allIds === [] ? [0] : $allIds)->get()->keyBy('product_id');
        $soldOut = BranchCatalogue::soldOutAt($branchId, $allIds);
        $categories = ProductCategory::query()->where('company_id', $companyId)
            ->whereIn('id', $categoryIds === [] ? [0] : $categoryIds)
            ->orderBy('display_order')->orderBy('id')->get();
        $productBindings = DB::table('pos_addon_group_products')
            ->whereIn('product_id', $groupProductIds === [] ? [0] : $groupProductIds)
            ->orderBy('display_order')->orderBy('id')->get()->groupBy('product_id');
        $categoryBindings = DB::table('pos_addon_group_categories')
            ->whereIn('category_id', array_values(array_unique(array_merge($categoryIds, $groupCategoryIds))) ?: [0])
            ->orderBy('id')->get()->groupBy('category_id');
        $boundGroupIds = $productBindings->flatten(1)->merge($categoryBindings->flatten(1))
            ->pluck('add_on_group_id')->map(static fn ($id): int => (int) $id)
            ->unique()->values()->all();

        $addonGroups = AddOnGroup::query()->where('company_id', $companyId)->where('status', 'active')
            ->where(function (Builder $query) use ($boundGroupIds): void {
                $query->where('is_global', true);
                if ($boundGroupIds !== []) {
                    $query->orWhereIn('id', $boundGroupIds);
                }
            })->orderBy('display_order')->orderBy('id')->get();
        $activeGroupIds = $addonGroups->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $activeGroupSet = array_fill_keys($activeGroupIds, true);
        $globalGroupIds = $addonGroups->filter(static fn (AddOnGroup $group): bool => (bool) $group->is_global)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $addonsByGroup = AddOn::query()->where('company_id', $companyId)->where('status', 'active')
            ->whereIn('add_on_group_id', $activeGroupIds === [] ? [0] : $activeGroupIds)
            ->orderBy('display_order')->orderBy('id')->get()->groupBy('add_on_group_id');
        $addonAvailability = $this->addonAvailability->handle(
            $companyId,
            $branchId,
            $addonsByGroup->flatten(1),
            $at,
        );
        $groupOrder = array_flip($activeGroupIds);
        $groupIdsFor = function (Product $product) use ($productBindings, $categoryBindings, $globalGroupIds, $activeGroupSet, $groupOrder): array {
            if ($product->isCombo()) {
                return [];
            }
            $groupIds = array_values(array_unique(array_merge(
                $globalGroupIds,
                $this->bindingIds($productBindings->get($product->id), $activeGroupSet),
                $product->category_id === null
                    ? []
                    : $this->bindingIds($categoryBindings->get($product->category_id), $activeGroupSet),
            )));
            usort($groupIds, static fn (int $left, int $right): int => ($groupOrder[$left] ?? PHP_INT_MAX) <=> ($groupOrder[$right] ?? PHP_INT_MAX));

            return $groupIds;
        };
        $availabilityOf = static fn (Product $product): QrProductAvailability => QrProductAvailability::evaluate(
            $product, $branchProducts->get($product->id), $at, isset($soldOut[(int) $product->id]),
        );
        $slotsByCombo = $slots->groupBy('combo_product_id');
        $taxPolicy = CompanyTaxPolicy::for($companyId);
        $shownOptions = static fn (object $slot): Collection => $options->get($slot->id, collect())
            ->filter(static fn (object $option): bool => $optionProducts->has((int) $option->product_id))->values();
        // Fix order A-1 (L2) — a combo's cooking figure: its own value, else
        // the longest option this branch sells today (the device config's rule).
        $comboCooking = static fn (Product $combo): ?int => CookingTime::comboFigure($combo, $slotsByCombo->get($combo->id, collect())
            ->flatMap(static fn (object $slot): Collection => $shownOptions($slot))
            ->map(static fn (object $option): ?Product => $optionProducts->get((int) $option->product_id))
            ->filter(static fn (?Product $item): bool => $item !== null && (string) $item->status === 'active'
                && BranchCatalogue::availableAt($item, $branchProducts->get($item->id)))
            ->map(static fn (Product $item): ?int => CookingTime::of($item)));
        // Fix order A-1 (L3) — a combo is unavailable while a required slot
        // (min >= 1) has no available option left: the reason is that of the
        // slot's first option (sold out, outside hours or dates, …), or
        // outside_dates when every option has left the menu.
        $optionAvailable = static function (object $option) use ($optionProducts, $availabilityOf): bool {
            $item = $optionProducts->get((int) $option->product_id);

            return $item !== null && $availabilityOf($item)->available;
        };
        $comboAvailability = static function (Product $combo) use ($availabilityOf, $slotsByCombo, $shownOptions, $optionAvailable, $optionProducts): QrProductAvailability {
            $own = $availabilityOf($combo);
            if (! $own->available) {
                return $own;
            }
            foreach ($slotsByCombo->get($combo->id, collect()) as $slot) {
                if ((int) $slot->min_choices < 1 || $shownOptions($slot)->contains($optionAvailable)) {
                    continue;
                }
                $first = $shownOptions($slot)->first();

                return QrProductAvailability::unavailable($first === null
                    ? QrProductAvailability::OUTSIDE_DATES
                    : (string) $availabilityOf($optionProducts->get((int) $first->product_id))->reason);
            }

            return $own;
        };
        $requiredAddonCost = $this->requiredAddonCost($groupIdsFor, $addonGroups->keyBy('id'), $addonsByGroup, $addonAvailability);
        $mealsByProduct = $this->meals($products, $slotsByCombo, $shownOptions, $optionProducts, $comboAvailability, $optionAvailable, $requiredAddonCost);

        return [
            'branding' => app(ReadQrBranding::class)->handle($companyId, $branchId),
            'tax' => [
                'vat_registered' => $taxPolicy->vatRegistered,
                // The merchant's switch (the device config's company.tax value) …
                'prices_include_vat' => $taxPolicy->pricesIncludeVat,
                // … and whether a new QR bill actually prices VAT inside (registered and on).
                'prices_include_tax' => $taxPolicy->pricesIncludeTax(),
            ],
            'categories' => $categories->map(function (ProductCategory $category) use ($categoryBindings, $activeGroupSet): array {
                return [
                    'id' => (int) $category->id,
                    'uuid' => $category->uuid,
                    'name' => $category->name,
                    'name_ar' => $category->name_ar,
                    'description' => $category->description,
                    'image_url' => $category->image_url,
                    'display_order' => (int) $category->display_order,
                    'status' => $category->status,
                    'addon_group_ids' => $this->bindingIds($categoryBindings->get($category->id), $activeGroupSet),
                ];
            })->values()->all(),
            'products' => $products->map(function (Product $product) use (
                $availabilityOf, $groupIdsFor, $soldOut, $slotsByCombo, $optionProducts, $shownOptions, $mealsByProduct,
                $comboAvailability, $comboCooking,
            ): array {
                $availability = $product->isCombo() ? $comboAvailability($product) : $availabilityOf($product);
                $basePriceBaisas = Money::toBaisas($product->base_price);
                $row = [
                    'id' => (int) $product->id,
                    'uuid' => $product->uuid,
                    'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'name_ar' => $product->name_ar,
                    'description' => $product->description,
                    'description_ar' => $product->description_ar,
                    'image_url' => $product->image_url,
                    'base_price_baisas' => $basePriceBaisas,
                    'base_price_display' => Money::toOmr($basePriceBaisas),
                    'display_order' => (int) $product->display_order,
                    'status' => $product->status,
                    'stock_mode' => $product->stock_mode,
                    'product_type' => (string) ($product->product_type ?? Product::TYPE_STANDARD),
                    'available_from' => $product->available_from,
                    'available_until' => $product->available_until,
                    'available' => $availability->available,
                    'unavailable_reason' => $availability->reason,
                    'sold_out' => isset($soldOut[(int) $product->id]),
                    'addon_group_ids' => $groupIdsFor($product),
                    // LAUNCH review add-on — the cooking time (a combo: its
                    // own, else its longest option) and the meals this
                    // product is the main of ("Make it a meal?").
                    'cooking_minutes' => $product->isCombo() ? $comboCooking($product) : CookingTime::of($product),
                    'meals' => $mealsByProduct[(int) $product->id] ?? [],
                ];
                if ($product->isCombo()) {
                    $row['combo'] = ['slots' => $slotsByCombo->get($product->id, collect())->map(
                        static fn (object $slot): array => [
                            'id' => (int) $slot->id,
                            'name' => $slot->name,
                            'name_ar' => $slot->name_ar,
                            'min' => (int) $slot->min_choices,
                            'max' => (int) $slot->max_choices,
                            'sort_order' => (int) $slot->sort_order,
                            'is_main' => (bool) ($slot->is_main ?? false),
                            'options' => $shownOptions($slot)
                                ->map(static function (object $option) use ($optionProducts, $availabilityOf, $groupIdsFor, $soldOut): array {
                                    /** @var Product $item */
                                    $item = $optionProducts->get((int) $option->product_id);
                                    $itemAvailability = $availabilityOf($item);
                                    $extra = Money::toBaisas($option->extra_price);

                                    return [
                                        'product_id' => (int) $item->id,
                                        'name' => $item->name,
                                        'name_ar' => $item->name_ar,
                                        'description' => $item->description,
                                        'description_ar' => $item->description_ar,
                                        'image_url' => $item->image_url,
                                        'extra_price_baisas' => $extra,
                                        'extra_price_display' => Money::toOmr($extra),
                                        'is_default' => (bool) $option->is_default,
                                        'sort_order' => (int) $option->sort_order,
                                        'available' => $itemAvailability->available,
                                        'unavailable_reason' => $itemAvailability->reason,
                                        'sold_out' => isset($soldOut[(int) $item->id]),
                                        'addon_group_ids' => $groupIdsFor($item),
                                        'cooking_minutes' => CookingTime::of($item),
                                    ];
                                })->values()->all(),
                        ],
                    )->values()->all()];
                }

                return $row;
            })->values()->all(),
            'addon_groups' => $addonGroups->map(function (AddOnGroup $group) use (
                $addonsByGroup,
                $addonAvailability,
            ): array {
                /** @var Collection<int, AddOn> $addons */
                $addons = $addonsByGroup->get($group->id, collect());

                return [
                    'id' => (int) $group->id,
                    'uuid' => $group->uuid,
                    'name' => $group->name,
                    'name_ar' => $group->name_ar,
                    'selection_mode' => $group->selection_mode,
                    'min_selections' => $group->min_selections !== null ? (int) $group->min_selections : null,
                    'max_selections' => $group->max_selections !== null ? (int) $group->max_selections : null,
                    'is_global' => (bool) $group->is_global,
                    'display_order' => (int) $group->display_order,
                    // LAUNCH review add-on — 'extras' | 'remove' | 'instructions'.
                    'kind' => (string) ($group->kind ?? 'extras'),
                    'addons' => $addons->map(static function (AddOn $addon) use ($addonAvailability): array {
                        $priceDeltaBaisas = Money::toBaisas($addon->price_delta);
                        $availability = $addonAvailability->get((int) $addon->id, [
                            'available' => false,
                            'reason' => ResolveQrAddOnAvailabilityAction::LINKED_PRODUCT_UNAVAILABLE,
                        ]);

                        return [
                            'id' => (int) $addon->id,
                            'uuid' => $addon->uuid,
                            'add_on_group_id' => (int) $addon->add_on_group_id,
                            'name' => $addon->name,
                            'name_ar' => $addon->name_ar,
                            'price_delta_baisas' => $priceDeltaBaisas,
                            'price_delta_display' => Money::toOmr($priceDeltaBaisas),
                            'is_default' => (bool) $addon->is_default,
                            'linked_product_id' => $addon->linked_product_id !== null
                                ? (int) $addon->linked_product_id
                                : null,
                            'available' => $availability['available'],
                            'unavailable_reason' => $availability['reason'],
                            'display_order' => (int) $addon->display_order,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * LAUNCH review add-on (owner decision D9) — "Make it a meal?": per
     * product, the combos on this menu and available now (on sale, inside
     * their hours, not sold out, sold here, every required slot still has an
     * available option) whose MAIN slot — a single pick, min = max = 1
     * (tester call 15) — offers it as an available option.
     *
     * Fix order A-1 (L6) — price_from is the true cheapest completion: the
     * combo's price + this option's extra + its required add-ons, plus, for
     * every other slot, min × the cheapest available option (its extra and
     * its own required add-ons; repeats allowed). Only available options and
     * add-ons count; an option that cannot be completed is skipped.
     *
     * @param  Collection<int, Product>  $products
     * @param  Collection<int|string, Collection<int, object>>  $slotsByCombo
     * @param  callable(object): Collection<int, object>  $shownOptions
     * @param  Collection<int|string, Product>  $optionProducts
     * @param  callable(Product): QrProductAvailability  $comboAvailability
     * @param  callable(object): bool  $optionAvailable
     * @param  callable(Product): ?int  $requiredAddonCost
     * @return array<int, list<array{combo_product_id: int, slot_id: int, name: string, name_ar: string|null, image_url: string|null, price_from_baisas: int}>>
     */
    private function meals(Collection $products, Collection $slotsByCombo, callable $shownOptions, Collection $optionProducts,
        callable $comboAvailability, callable $optionAvailable, callable $requiredAddonCost): array
    {
        // The cost of ONE pick of an option, or null when it cannot be picked now.
        $pickCost = static function (object $option) use ($optionProducts, $optionAvailable, $requiredAddonCost): ?int {
            if (! $optionAvailable($option)) {
                return null;
            }
            $addons = $requiredAddonCost($optionProducts->get((int) $option->product_id));

            return $addons === null ? null : Money::toBaisas($option->extra_price) + $addons;
        };
        $meals = [];
        foreach ($products as $combo) {
            if (! $combo->isCombo() || ! $comboAvailability($combo)->available) {
                continue;
            }
            $slots = $slotsByCombo->get($combo->id, collect());
            $main = $slots->first(static fn (object $slot): bool => (bool) ($slot->is_main ?? false));
            if ($main === null || (int) $main->min_choices !== 1 || (int) $main->max_choices !== 1) {
                continue;
            }
            $fill = 0;
            foreach ($slots as $slot) {
                if ((int) $slot->id === (int) $main->id || (int) $slot->min_choices <= 0) {
                    continue;
                }
                $cheapest = $shownOptions($slot)->map($pickCost)->filter(static fn (?int $cost): bool => $cost !== null)->min();
                if ($cheapest === null) {
                    continue 2;
                }
                $fill += (int) $slot->min_choices * (int) $cheapest;
            }
            foreach ($shownOptions($main) as $option) {
                $cost = $pickCost($option);
                if ($cost === null) {
                    continue;
                }
                $meals[(int) $option->product_id][] = [
                    'combo_product_id' => (int) $combo->id,
                    'slot_id' => (int) $main->id,
                    'name' => (string) $combo->name,
                    'name_ar' => $combo->name_ar,
                    'image_url' => $combo->image_url,
                    'price_from_baisas' => Money::toBaisas($combo->base_price) + $cost + $fill,
                ];
            }
        }

        return $meals;
    }

    /**
     * Fix order A-1 (L6) — the cheapest set of a product's REQUIRED add-ons
     * (each group's min_selections cheapest available options), or null when a
     * required group cannot be filled now.
     *
     * @param  callable(Product): list<int>  $groupIdsFor
     * @param  Collection<int|string, AddOnGroup>  $groupsById
     * @param  Collection<int|string, Collection<int, AddOn>>  $addonsByGroup
     * @param  Collection<int, array{available: bool, reason: string|null}>  $addonAvailability
     * @return callable(Product|null): ?int
     */
    private function requiredAddonCost(callable $groupIdsFor, Collection $groupsById, Collection $addonsByGroup, Collection $addonAvailability): callable
    {
        return static function (?Product $item) use ($groupIdsFor, $groupsById, $addonsByGroup, $addonAvailability): ?int {
            if ($item === null) {
                return null;
            }
            $cost = 0;
            foreach ($groupIdsFor($item) as $groupId) {
                $minimum = (int) ($groupsById->get($groupId)?->min_selections ?? 0);
                if ($minimum <= 0) {
                    continue;
                }
                $prices = $addonsByGroup->get($groupId, collect())
                    ->filter(static fn (AddOn $addon): bool => (bool) ($addonAvailability->get((int) $addon->id)['available'] ?? false))
                    ->map(static fn (AddOn $addon): int => Money::toBaisas($addon->price_delta))->sort()->values();
                if ($prices->count() < $minimum) {
                    return null;
                }
                $cost += (int) $prices->take($minimum)->sum();
            }

            return $cost;
        };
    }

    /** @param Collection<int, object>|null $rows @param array<int, true> $activeGroupSet @return list<int> */
    private function bindingIds(?Collection $rows, array $activeGroupSet): array
    {
        if ($rows === null) {
            return [];
        }

        return $rows->pluck('add_on_group_id')->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => isset($activeGroupSet[$id]))
            ->unique()->values()->all();
    }
}
