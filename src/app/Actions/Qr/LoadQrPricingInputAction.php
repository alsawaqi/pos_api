<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\BranchProduct;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Product;
use App\Support\Money;
use App\Support\Pricing\CompanyTaxPolicy;
use App\Support\Pricing\DiscountRule;
use App\Support\Pricing\Discounts;
use App\Support\Pricing\DiscountTarget;
use App\Support\Pricing\OfferSpec;
use App\Support\Pricing\OrderDiscountSelection;
use App\Support\Pricing\PricingInput;
use App\Support\Pricing\PricingLine;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolves unpriced QR lines against live DB rows and builds the canonical engine input. */
final class LoadQrPricingInputAction
{
    public function __construct(
        private readonly QrBranchProductQuery $products,
        private readonly ResolveQrAddOnAvailabilityAction $addonAvailability,
    ) {}

    /**
     * @param  list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string|null}>  $lines
     * @param  bool|null  $pricesIncludeTax  LAUNCH-P4: an existing bill's own
     *                                       tax mode; null = the merchant's
     *                                       current policy (a new bill)
     */
    public function handle(
        int $companyId,
        int $branchId,
        array $lines,
        ?DateTimeImmutable $now = null,
        ?bool $pricesIncludeTax = null,
    ): QrPricingLoadResult {
        return $this->load($companyId, $branchId, $lines, $now, false, $pricesIncludeTax);
    }

    /** Staff may sell tablet-hidden products, but never internal ingredients.
     * @param  list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string|null}>  $lines
     */
    public function handleForStaff(int $companyId, int $branchId, array $lines, ?DateTimeImmutable $now = null, ?bool $pricesIncludeTax = null): QrPricingLoadResult
    {
        return $this->load($companyId, $branchId, $lines, $now, true, $pricesIncludeTax);
    }

    /** @param list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string|null}> $lines */
    private function load(
        int $companyId,
        int $branchId,
        array $lines,
        ?DateTimeImmutable $now,
        bool $staff,
        ?bool $pricesIncludeTax = null,
    ): QrPricingLoadResult {
        $now ??= DateTimeImmutable::createFromInterface(now());
        $normalisedLines = $this->normaliseLines($lines);
        $productIds = array_values(array_unique(array_column($normalisedLines, 'product_id')));
        $query = $staff
            ? $this->products->soldByBranch($companyId, $branchId)->where('is_internal', false)
            : $this->products->forBranch($companyId, $branchId);
        $products = $query->whereIn('pos_products.id', $productIds)->get()->keyBy('id');
        $branchProducts = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)->get()->keyBy('product_id');

        foreach ($productIds as $productId) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            /** @var BranchProduct|null $branchProduct */
            $branchProduct = $branchProducts->get($productId);
            if ($product === null || ! QrProductAvailability::evaluate($product, $branchProduct, $now)->available) {
                throw new QrCatalogueException(
                    QrCatalogueException::PRODUCT_UNAVAILABLE,
                    'This product is not available.',
                );
            }
        }

        $applicableGroups = $this->applicableGroups($companyId, $products);
        $requestedAddonIds = collect($normalisedLines)->pluck('addon_ids')->flatten()
            ->unique()->values()->all();
        $addons = AddOn::query()->where('company_id', $companyId)->where('status', 'active')
            ->whereIn('id', $requestedAddonIds === [] ? [0] : $requestedAddonIds)
            ->get()->keyBy('id');
        $addonAvailability = $this->addonAvailability->handle(
            $companyId,
            $branchId,
            $addons->values(),
            $now,
        );

        $pricingLines = [];
        $resolvedLines = [];
        foreach ($normalisedLines as $line) {
            /** @var Product $product */
            $product = $products->get($line['product_id']);
            /** @var Collection<int, AddOnGroup> $groups */
            $groups = $applicableGroups->get($product->id, collect());
            $groupSet = array_fill_keys(
                $groups->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                true,
            );
            $resolvedAddons = [];
            $selectedByGroup = [];
            foreach ($line['addon_ids'] as $addonId) {
                /** @var AddOn|null $addon */
                $addon = $addons->get($addonId);
                if ($addon === null
                    || ! isset($groupSet[(int) $addon->add_on_group_id])
                    || ! $addonAvailability->get((int) $addon->id, ['available' => false])['available']) {
                    throw new QrCatalogueException(
                        QrCatalogueException::ADDON_UNAVAILABLE,
                        'This add-on is not available.',
                    );
                }
                $groupId = (int) $addon->add_on_group_id;
                $selectedByGroup[$groupId] = ($selectedByGroup[$groupId] ?? 0) + 1;
                $resolvedAddons[] = new QrResolvedAddOn(
                    addon: $addon,
                    priceDeltaBaisas: Money::toBaisas($addon->price_delta),
                );
            }

            foreach ($groups as $group) {
                $selected = $selectedByGroup[(int) $group->id] ?? 0;
                $minimum = $group->min_selections !== null ? (int) $group->min_selections : 0;
                $maximum = $group->max_selections !== null
                    ? (int) $group->max_selections
                    : ((string) $group->selection_mode === 'single' ? 1 : null);
                if ($selected < $minimum || ($maximum !== null && $selected > $maximum)) {
                    throw new QrCatalogueException(
                        QrCatalogueException::ADDON_SELECTION_INVALID,
                        'The selected add-ons are invalid.',
                    );
                }
            }

            $basePriceBaisas = Money::toBaisas($product->base_price);
            $unitPriceBaisas = $basePriceBaisas + array_sum(array_map(
                static fn (QrResolvedAddOn $resolved): int => $resolved->priceDeltaBaisas,
                $resolvedAddons,
            ));
            $pricingLines[] = new PricingLine(
                unitPriceBaisas: $unitPriceBaisas,
                qty: $line['qty'],
                productId: (int) $product->id,
                categoryId: $product->category_id !== null ? (int) $product->category_id : null,
            );
            $resolvedLines[] = new QrResolvedLine(
                product: $product,
                qty: $line['qty'],
                notes: $line['notes'],
                basePriceBaisas: $basePriceBaisas,
                unitPriceBaisas: $unitPriceBaisas,
                addons: $resolvedAddons,
            );
        }

        $discountRules = $this->discountRules($companyId);
        $autoOrderDiscount = Discounts::selectAutoOrderDiscount(
            $pricingLines, $discountRules, $now, $branchId, false,
        );
        // LAUNCH-P4 — the effective taxes (none when not VAT-registered) and
        // the bill's tax mode: the same rule as the devices' pricing package.
        $taxPolicy = CompanyTaxPolicy::for($companyId);

        return new QrPricingLoadResult(
            pricingInput: new PricingInput(
                lines: $pricingLines,
                now: $now,
                discountRules: $discountRules,
                offers: $this->offers($companyId),
                orderDiscount: $autoOrderDiscount === null
                    ? OrderDiscountSelection::none()
                    : Discounts::ruleAsOrderSelection($autoOrderDiscount),
                taxes: $taxPolicy->taxSpecs(),
                branchId: $branchId,
                pricesIncludeTax: $pricesIncludeTax ?? $taxPolicy->pricesIncludeTax(),
            ),
            resolvedLines: $resolvedLines,
            autoOrderDiscount: $autoOrderDiscount,
        );
    }

    /**
     * Classify staff requests against the same catalogue gates as handle(),
     * without treating catalogue drift as a failed outbox event.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{priceable: list<array<string, mixed>>, held: list<array{line_index: int, product_id: int, addon_id: ?int, reason: string}>}
     */
    public function classify(int $companyId, int $branchId, array $lines, ?DateTimeImmutable $now = null): array
    {
        $now ??= DateTimeImmutable::createFromInterface(now());
        $normalised = [];
        $held = [];
        foreach (array_values($lines) as $index => $line) {
            try {
                $normalised[$index] = $this->normaliseLines([$line])[0];
            } catch (QrCatalogueException $exception) {
                $held[$index] = [
                    'line_index' => $index, 'product_id' => (int) ($line['product_id'] ?? 0),
                    'addon_id' => null, 'reason' => $exception->codeName,
                ];
            }
        }
        $productIds = array_values(array_unique(array_column($normalised, 'product_id')));
        $products = $this->products->forBranch($companyId, $branchId)
            ->whereIn('pos_products.id', $productIds)->get()->keyBy('id');
        $branchProducts = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $groupsByProduct = $this->applicableGroups($companyId, $products);
        $addonIds = collect($normalised)->pluck('addon_ids')->flatten()->unique()->values()->all();
        $addons = AddOn::query()->where('company_id', $companyId)->where('status', 'active')
            ->whereIn('id', $addonIds)->get()->keyBy('id');
        $availableAddons = $this->addonAvailability->handle($companyId, $branchId, $addons->values(), $now);
        $priceable = [];
        foreach ($normalised as $index => $line) {
            $product = $products->get($line['product_id']);
            $availability = $product === null
                ? null : QrProductAvailability::evaluate($product, $branchProducts->get($product->id), $now);
            $reason = $product === null ? 'product_missing' : $availability->reason;
            $unavailableAddon = null;
            if ($reason === null) {
                $groups = $groupsByProduct->get($product->id, collect());
                $groupSet = array_fill_keys($groups->pluck('id')->all(), true);
                $selectedByGroup = [];
                foreach ($line['addon_ids'] as $addonId) {
                    $addon = $addons->get($addonId);
                    if ($addon === null || ! isset($groupSet[(int) $addon->add_on_group_id])
                        || ! $availableAddons->get($addonId, ['available' => false])['available']) {
                        $reason = QrCatalogueException::ADDON_UNAVAILABLE;
                        $unavailableAddon = $addonId;
                        break;
                    }
                    $groupId = (int) $addon->add_on_group_id;
                    $selectedByGroup[$groupId] = ($selectedByGroup[$groupId] ?? 0) + 1;
                }
                if ($reason === null) {
                    foreach ($groups as $group) {
                        $selected = $selectedByGroup[(int) $group->id] ?? 0;
                        $minimum = $group->min_selections !== null ? (int) $group->min_selections : 0;
                        $maximum = $group->max_selections !== null
                            ? (int) $group->max_selections
                            : ((string) $group->selection_mode === 'single' ? 1 : null);
                        if ($selected < $minimum || ($maximum !== null && $selected > $maximum)) {
                            $reason = QrCatalogueException::ADDON_SELECTION_INVALID;
                            break;
                        }
                    }
                }
            }
            if ($reason !== null) {
                $held[$index] = [
                    'line_index' => $index, 'product_id' => $line['product_id'],
                    'addon_id' => $unavailableAddon, 'reason' => $reason,
                ];
            } else {
                $priceable[] = $line;
            }
        }
        ksort($held);

        return ['priceable' => $priceable, 'held' => array_values($held)];
    }

    /**
     * @param  list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string|null}>  $lines
     * @return list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string}>
     */
    private function normaliseLines(array $lines): array
    {
        if ($lines === []) {
            throw new QrCatalogueException(QrCatalogueException::INVALID_LINE, 'The order lines are invalid.');
        }
        $normalised = [];
        foreach ($lines as $line) {
            if (! is_array($line)
                || ! isset($line['product_id'], $line['qty'], $line['addon_ids'])
                || ! array_key_exists('notes', $line)
                || ! is_int($line['product_id']) || $line['product_id'] < 1
                || ! is_int($line['qty']) || $line['qty'] < 1
                || ! is_array($line['addon_ids'])
                || ($line['notes'] !== null && ! is_string($line['notes']))) {
                throw new QrCatalogueException(QrCatalogueException::INVALID_LINE, 'The order lines are invalid.');
            }
            $addonIds = [];
            foreach (array_values($line['addon_ids']) as $addonId) {
                if (! is_int($addonId) || $addonId < 1 || in_array($addonId, $addonIds, true)) {
                    throw new QrCatalogueException(
                        QrCatalogueException::ADDON_SELECTION_INVALID,
                        'The selected add-ons are invalid.',
                    );
                }
                $addonIds[] = $addonId;
            }
            $normalised[] = [
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'addon_ids' => $addonIds,
                'notes' => $line['notes'] ?? '',
            ];
        }

        return $normalised;
    }

    /** @param Collection<int, Product> $products @return Collection<int, Collection<int, AddOnGroup>> */
    private function applicableGroups(int $companyId, Collection $products): Collection
    {
        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $categoryIds = $products->pluck('category_id')->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $productBindings = DB::table('pos_addon_group_products')->whereIn('product_id', $productIds)
            ->get()->groupBy('product_id');
        $categoryBindings = DB::table('pos_addon_group_categories')
            ->whereIn('category_id', $categoryIds === [] ? [0] : $categoryIds)
            ->get()->groupBy('category_id');
        $boundIds = $productBindings->flatten(1)->merge($categoryBindings->flatten(1))
            ->pluck('add_on_group_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $groups = AddOnGroup::query()->where('company_id', $companyId)->where('status', 'active')
            ->where(function (Builder $query) use ($boundIds): void {
                $query->where('is_global', true);
                if ($boundIds !== []) {
                    $query->orWhereIn('id', $boundIds);
                }
            })->orderBy('display_order')->orderBy('id')->get();
        $groupById = $groups->keyBy('id');
        $globalIds = $groups->filter(static fn (AddOnGroup $group): bool => (bool) $group->is_global)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return $products->mapWithKeys(function (Product $product) use (
            $productBindings, $categoryBindings, $globalIds, $groupById,
        ): array {
            $ids = array_merge(
                $globalIds,
                $productBindings->get($product->id, collect())->pluck('add_on_group_id')->all(),
                $product->category_id === null
                    ? []
                    : $categoryBindings->get($product->category_id, collect())->pluck('add_on_group_id')->all(),
            );
            $ids = array_values(array_unique(array_map('intval', $ids)));

            return [(int) $product->id => $groupById->only($ids)->values()];
        });
    }

    /** @return list<DiscountRule> */
    private function discountRules(int $companyId): array
    {
        $discounts = Discount::query()->where('company_id', $companyId)->where('status', 'active')
            ->where('requires_manager_approval', false)
            ->orderBy('id')->get();
        $targets = DB::table('pos_discount_targets')
            ->whereIn('discount_id', $discounts->pluck('id')->all() ?: [0])
            ->orderBy('id')->get()->groupBy('discount_id');

        return $discounts->map(function (Discount $discount) use ($targets): DiscountRule {
            return new DiscountRule(
                id: (int) $discount->id,
                name: (string) $discount->name,
                scope: (string) $discount->scope,
                amountType: (string) $discount->amount_type,
                fixedBaisas: $discount->amount_type === 'fixed' ? Money::toBaisas($discount->amount) : null,
                percent: $discount->amount_type === 'percent' ? (float) $discount->amount : null,
                validityStart: $this->immutable($discount->validity_start),
                validityEnd: $this->immutable($discount->validity_end),
                dayOfWeekMask: $discount->dayofweek_mask !== null ? (int) $discount->dayofweek_mask : null,
                timeStart: $discount->time_start,
                timeEnd: $discount->time_end,
                branchScope: $this->integerList($discount->branch_scope_json),
                stackable: (bool) $discount->stackable,
                requiresManagerApproval: (bool) $discount->requires_manager_approval,
                isActive: true,
                autoApply: (bool) $discount->auto_apply,
                targets: $targets->get($discount->id, collect())
                    ->map(static fn ($target): DiscountTarget => new DiscountTarget(
                        targetType: (string) $target->target_type,
                        targetId: (int) $target->target_id,
                    ))->values()->all(),
            );
        })->values()->all();
    }

    /** @return list<OfferSpec> */
    private function offers(int $companyId): array
    {
        return Offer::query()->where('company_id', $companyId)->where('status', 'active')
            ->where('auto_apply', true)->orderBy('id')->get()
            ->map(fn (Offer $offer): OfferSpec => new OfferSpec(
                id: (int) $offer->id,
                name: (string) $offer->name,
                type: (string) $offer->type,
                nameAr: $offer->name_ar,
                config: is_array($offer->config) ? $offer->config : [],
                autoApply: true,
                validityStart: $this->immutable($offer->validity_start),
                validityEnd: $this->immutable($offer->validity_end),
                dayOfWeekMask: $offer->dayofweek_mask !== null ? (int) $offer->dayofweek_mask : null,
                timeStart: $offer->time_start,
                timeEnd: $offer->time_end,
                branchScope: $this->integerList($offer->branch_scope_json),
                maxPerOrder: $offer->max_per_order !== null ? (int) $offer->max_per_order : null,
                isActive: true,
            ))->values()->all();
    }

    private function immutable(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null;
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', $value)) : [];
    }
}
