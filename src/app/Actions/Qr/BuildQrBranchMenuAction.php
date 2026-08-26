<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds the customer branch menu without the device catalogue's delta/purge semantics. */
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
        $at ??= DateTimeImmutable::createFromInterface(now());
        $products = $this->products->forBranch($companyId, $branchId)
            ->orderBy('display_order')->orderBy('id')->get();
        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $categoryIds = $products->pluck('category_id')->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $branchProducts = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds === [] ? [0] : $productIds)->get()->keyBy('product_id');
        $categories = ProductCategory::query()->where('company_id', $companyId)
            ->whereIn('id', $categoryIds === [] ? [0] : $categoryIds)
            ->orderBy('display_order')->orderBy('id')->get();
        $productBindings = DB::table('pos_addon_group_products')
            ->whereIn('product_id', $productIds === [] ? [0] : $productIds)
            ->orderBy('display_order')->orderBy('id')->get()->groupBy('product_id');
        $categoryBindings = DB::table('pos_addon_group_categories')
            ->whereIn('category_id', $categoryIds === [] ? [0] : $categoryIds)
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

        return [
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
                $at, $branchProducts, $productBindings, $categoryBindings,
                $globalGroupIds, $activeGroupSet, $groupOrder,
            ): array {
                /** @var BranchProduct|null $branchProduct */
                $branchProduct = $branchProducts->get($product->id);
                $availability = QrProductAvailability::evaluate($product, $branchProduct, $at);
                $groupIds = array_merge(
                    $globalGroupIds,
                    $this->bindingIds($productBindings->get($product->id), $activeGroupSet),
                    $product->category_id === null
                        ? []
                        : $this->bindingIds($categoryBindings->get($product->category_id), $activeGroupSet),
                );
                $groupIds = array_values(array_unique($groupIds));
                usort($groupIds, static fn (int $left, int $right): int => ($groupOrder[$left] ?? PHP_INT_MAX) <=> ($groupOrder[$right] ?? PHP_INT_MAX));
                $basePriceBaisas = Money::toBaisas($product->base_price);

                return [
                    'id' => (int) $product->id,
                    'uuid' => $product->uuid,
                    'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'name_ar' => $product->name_ar,
                    'description' => $product->description,
                    'image_url' => $product->image_url,
                    'base_price_baisas' => $basePriceBaisas,
                    'base_price_display' => Money::toOmr($basePriceBaisas),
                    'display_order' => (int) $product->display_order,
                    'status' => $product->status,
                    'stock_mode' => $product->stock_mode,
                    'available_from' => $product->available_from,
                    'available_until' => $product->available_until,
                    'available' => $availability->available,
                    'unavailable_reason' => $availability->reason,
                    'addon_group_ids' => $groupIds,
                ];
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
