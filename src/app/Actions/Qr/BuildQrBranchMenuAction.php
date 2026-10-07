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
use App\Support\Catalogue\ComboLines;
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
 * The same menu serves the QR menu and the customer tablet (LAUNCH-P6).
 *
 * LAUNCH-P4:
 *  - only active products (L4: an inactive product is left out, not
 *    greyed), in-store ones shown on the QR menu (channels), sold at this
 *    branch by its branch scope; a product switched off here stays greyed;
 *  - a hand-set "sold out" product is shown, greyed and not orderable
 *    (`sold_out: true`, unavailable_reason 'sold_out');
 *  - `tax` says whether menu prices include VAT; windows use the
 *    merchant's wall clock (H9).
 *
 * LAUNCH combo add-on (replaces the slots and the "main slot" meals):
 *  - a combo carries `combo.lines[]` — fixed lines (the item × quantity and
 *    its upgrades) and choice lines (the question, pick N and the items it
 *    offers), each item resolved for this branch with its name, photo,
 *    price, availability and add-on groups ({@see linePayload()});
 *  - a combo is unavailable while a fixed item is unavailable or a choice
 *    line has no available item left (repeats are allowed, so one is enough);
 *  - top-level `meals[]` lists the meals on sale today ({@see mealPayload()});
 *    a product that is the main of an AVAILABLE meal carries its `meal_id`
 *    ("Make it a meal? +meal_price"), else null.
 */
final class BuildQrBranchMenuAction
{
    public function __construct(
        private readonly QrBranchProductQuery $products,
        private readonly ResolveQrAddOnAvailabilityAction $addonAvailability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $companyId, int $branchId, ?DateTimeInterface $at = null, ?string $orderType = null): array
    {
        $at = BusinessClock::local($at);
        // LAUNCH review add-on — a product or combo outside its limited-time
        // dates is left out (not greyed), and so is such an item of a line.
        $today = SaleDates::day($at);
        $products = SaleDates::onSale($this->products->forBranch($companyId, $branchId)->where('status', 'active'), $today)
            ->orderBy('display_order')->orderBy('id')->get();

        // Combo and meal lines, and the products they may serve (an item need
        // not be on the menu itself: a side sold only inside a meal).
        $comboIds = $products->filter(static fn (Product $p): bool => $p->isCombo())->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $meals = ComboLines::meals($companyId, $today);
        $lines = ComboLines::load($comboIds, $meals->keys()->map(static fn ($id): int => (int) $id)->all());
        $refs = ComboLines::references($lines['combos']->flatten(1)->merge($lines['meals']->flatten(1)));
        $itemProducts = SaleDates::onSale($this->products->soldByBranch($companyId, $branchId)->where('is_internal', false)
            ->where('status', 'active')->where('product_type', Product::TYPE_STANDARD)
            ->where(static fn (Builder $q) => $q->whereIn('pos_products.id', $refs['products'] ?: [0])
                ->orWhereIn('pos_products.category_id', $refs['categories'] ?: [0])), $today)
            ->orderBy('display_order')->orderBy('id')->get()->keyBy('id');

        $groupProducts = $products->reject(static fn (Product $p): bool => $p->isCombo())
            ->keyBy('id')->union($itemProducts);
        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $allIds = array_values(array_unique(array_merge($productIds, $itemProducts->keys()->map(static fn ($id): int => (int) $id)->all())));
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
            // Fix order PK-A1 (L3) — the session's order type (table / quick).
            $orderType,
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
        $linesAvailability = static function (Collection $ownerLines) use ($itemProducts, $availabilityOf): QrProductAvailability {
            foreach ($ownerLines as $line) {
                if ($line->kind === ComboLines::FIXED) {
                    $item = $itemProducts->get($line->product_id);
                    if ($item === null) {
                        return QrProductAvailability::unavailable(QrProductAvailability::OUTSIDE_DATES);
                    }
                    $own = $availabilityOf($item);
                    if (! $own->available) {
                        return $own;
                    }

                    continue;
                }
                $offered = $itemProducts->filter(static fn (Product $item): bool => ComboLines::choiceOffers($line, $item));
                if (! $offered->contains(static fn (Product $item): bool => $availabilityOf($item)->available)) {
                    $first = $offered->first();

                    return QrProductAvailability::unavailable($first === null
                        ? QrProductAvailability::OUTSIDE_DATES
                        : (string) $availabilityOf($first)->reason);
                }
            }

            return QrProductAvailability::available();
        };
        $comboAvailability = static function (Product $combo) use ($availabilityOf, $lines, $linesAvailability): QrProductAvailability {
            $own = $availabilityOf($combo);

            return $own->available ? $linesAvailability($lines['combos']->get((int) $combo->id, collect())) : $own;
        };
        // A combo's cooking figure: its own, else the longest item its lines serve here.
        $lineCooking = static fn (Collection $ownerLines): array => $itemProducts
            ->filter(static fn (Product $item): bool => $ownerLines->contains(static fn (object $line): bool => (int) $item->id === $line->product_id
                || $line->upgrades->has((int) $item->id) || ComboLines::choiceOffers($line, $item)))
            ->filter(static fn (Product $item): bool => BranchCatalogue::availableAt($item, $branchProducts->get($item->id)))
            ->map(static fn (Product $item): ?int => CookingTime::of($item))->values()->all();
        $itemRow = static function (Product $item) use ($availabilityOf, $groupIdsFor, $soldOut): array {
            $availability = $availabilityOf($item);

            return [
                'product_id' => (int) $item->id,
                'name' => $item->name,
                'name_ar' => $item->name_ar,
                'description' => $item->description,
                'description_ar' => $item->description_ar,
                'image_url' => $item->image_url,
                'base_price_baisas' => Money::toBaisas($item->base_price),
                'available' => $availability->available,
                'unavailable_reason' => $availability->reason,
                'sold_out' => isset($soldOut[(int) $item->id]),
                'addon_group_ids' => $groupIdsFor($item),
                'cooking_minutes' => CookingTime::of($item),
            ];
        };
        $linePayload = fn (object $line): array => $this->linePayload($line, $itemProducts, $itemRow);
        $mealRows = $meals->map(static function (object $meal) use ($lines, $linesAvailability, $linePayload): array {
            $mealLines = $lines['meals']->get($meal->id, collect());
            $availability = $linesAvailability($mealLines);
            $price = Money::toBaisas($meal->meal_price);

            return [
                'id' => (int) $meal->id,
                'uuid' => $meal->uuid,
                'name' => $meal->name,
                'name_ar' => $meal->name_ar,
                'meal_price_baisas' => $price,
                'meal_price_display' => Money::toOmr($price),
                'sort_order' => (int) $meal->sort_order,
                'available' => $availability->available,
                'unavailable_reason' => $availability->reason,
                'lines' => $mealLines->map($linePayload)->values()->all(),
            ];
        })->values();
        $availableMeals = $meals->filter(static fn (object $meal): bool => (bool) ($mealRows->firstWhere('id', (int) $meal->id)['available'] ?? false));
        $taxPolicy = CompanyTaxPolicy::for($companyId);

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
                $availabilityOf, $groupIdsFor, $soldOut, $comboAvailability, $lines, $lineCooking, $linePayload, $availableMeals,
            ): array {
                $availability = $product->isCombo() ? $comboAvailability($product) : $availabilityOf($product);
                $basePriceBaisas = Money::toBaisas($product->base_price);
                $comboLines = $lines['combos']->get((int) $product->id, collect());
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
                    // own, else its longest item).
                    'cooking_minutes' => $product->isCombo()
                        ? CookingTime::comboFigure($product, $lineCooking($comboLines))
                        : CookingTime::of($product),
                    // LAUNCH combo add-on — "Make it a meal?": the available meal this is the main of.
                    'meal_id' => $product->isCombo() ? null : ComboLines::mealFor($availableMeals, $product)?->id,
                ];
                if ($product->isCombo()) {
                    $row['combo'] = ['lines' => $comboLines->map($linePayload)->values()->all()];
                }

                return $row;
            })->values()->all(),
            'meals' => $mealRows->all(),
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
                    // LAUNCH combo add-on — a 'remove' option may be below 0.
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
     * LAUNCH combo add-on — one combo or meal line on the menu (the device
     * config keys, each item resolved):
     *
     *   {id, kind: 'fixed' | 'choice', sort_order,
     *    product_id, quantity, product: ITEM | null,           fixed
     *    upgrades: [ITEM + {upgrade_price_baisas, upgrade_price_display, sort_order}],
     *    name, name_ar, category_id, pick_count,               choice
     *    items: [ITEM + {extra_price_baisas, extra_price_display}]}
     *
     * ITEM = {product_id, name, name_ar, description, description_ar,
     * image_url, base_price_baisas, available, unavailable_reason, sold_out,
     * addon_group_ids, cooking_minutes}. Upgrades and items list the products
     * on sale today (unavailable ones greyed, not left out).
     *
     * @param  Collection<int|string, Product>  $itemProducts
     * @param  callable(Product): array<string, mixed>  $itemRow
     * @return array<string, mixed>
     */
    private function linePayload(object $line, Collection $itemProducts, callable $itemRow): array
    {
        $fixed = $line->kind === ComboLines::FIXED;
        $item = $fixed ? $itemProducts->get($line->product_id) : null;

        return [
            'id' => $line->id,
            'kind' => $line->kind,
            'sort_order' => $line->sort_order,
            'product_id' => $fixed ? $line->product_id : null,
            'quantity' => $fixed ? $line->quantity : null,
            'product' => $item !== null ? $itemRow($item) : null,
            'upgrades' => $fixed ? $line->upgrades->filter(static fn (object $u): bool => $itemProducts->has((int) $u->product_id))
                ->map(static function (object $u) use ($itemProducts, $itemRow): array {
                    $price = Money::toBaisas($u->upgrade_price);

                    return $itemRow($itemProducts->get((int) $u->product_id)) + [
                        'upgrade_price_baisas' => $price,
                        'upgrade_price_display' => Money::toOmr($price),
                        'sort_order' => (int) $u->sort_order,
                    ];
                })->values()->all() : [],
            'name' => $fixed ? null : $line->name,
            'name_ar' => $fixed ? null : $line->name_ar,
            'category_id' => $fixed ? null : $line->category_id,
            'pick_count' => $fixed ? null : $line->pick_count,
            'items' => $fixed ? [] : $itemProducts->filter(static fn (Product $p): bool => ComboLines::choiceOffers($line, $p))
                ->map(static function (Product $p) use ($line, $itemRow): array {
                    $extra = ComboLines::choiceExtraBaisas($line, (int) $p->id);

                    return $itemRow($p) + ['extra_price_baisas' => $extra, 'extra_price_display' => Money::toOmr($extra)];
                })->values()->all(),
        ];
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
