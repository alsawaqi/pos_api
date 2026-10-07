<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\BranchProduct;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Product;
use App\Support\BusinessClock;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Catalogue\ComboLines;
use App\Support\Catalogue\SaleDates;
use App\Support\Money;
use App\Support\Orders\OneLineNote;
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

/**
 * Resolves unpriced QR lines against live DB rows and builds the canonical engine input.
 *
 * LAUNCH combo add-on (replaces the LAUNCH-P4 slots):
 *  - a combo line (a combo product) or a meal line (`meal_id` on a main
 *    product) carries `combo` — its items per ONE combo / meal, each
 *    {line_id, product_id, qty, addon_ids, notes}:
 *      fixed line   the line's product or one of its upgrades, the qty
 *                   adding up to the line's quantity; a fixed line sent with
 *                   no entry is served as is (its product × quantity);
 *      choice line  items the line offers (its category, not unticked),
 *                   repeats allowed, the qty adding up to pick N exactly
 *                   (nothing is pre-picked: a missing choice is refused).
 *    Each item's own add-ons follow its own groups.
 *  - unit price (the device wire rule, mithqal_pricing v0.4.0):
 *      standard  max(0, base + Σ add-ons)
 *      combo     max(0, combo price + Σ items qty × (extra / upgrade price
 *                + its add-ons))
 *      meal      max(0, main price + Σ main add-ons + meal price + Σ items …)
 *    A Remove option may be below 0; the line never goes below 0. A combo
 *    line takes no add-ons of its own; a meal line's add-ons are the main's.
 *  - discounts and offers see a meal line as no product / category (only
 *    order-wide ones reach it); a combo line is its combo product.
 * LAUNCH-P4:
 *  - sold out (a hand-set switch per branch) refuses a product or a combo
 *    choice; QR is an in-store channel (sold_in_store); staff rounds use the
 *    staff set (M5: the QR menu switch does not apply to staff).
 *  - windows use the merchant's wall clock (H9); taxes are the effective
 *    ones in the bill's own mode.
 */
final class LoadQrPricingInputAction
{
    public function __construct(
        private readonly QrBranchProductQuery $products,
        private readonly ResolveQrAddOnAvailabilityAction $addonAvailability,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
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
        ?string $orderType = null,
    ): QrPricingLoadResult {
        return $this->load($companyId, $branchId, $lines, $now, false, $pricesIncludeTax, $orderType);
    }

    /** Staff may sell tablet-hidden products, but never internal ingredients.
     * @param  list<array<string, mixed>>  $lines
     */
    public function handleForStaff(int $companyId, int $branchId, array $lines, ?DateTimeImmutable $now = null, ?bool $pricesIncludeTax = null, ?string $orderType = null): QrPricingLoadResult
    {
        return $this->load($companyId, $branchId, $lines, $now, true, $pricesIncludeTax, $orderType);
    }

    /** @param list<array<string, mixed>> $lines */
    private function load(
        int $companyId,
        int $branchId,
        array $lines,
        ?DateTimeImmutable $now,
        bool $staff,
        ?bool $pricesIncludeTax = null,
        ?string $orderType = null,
    ): QrPricingLoadResult {
        $now = BusinessClock::local($now);
        $normalisedLines = $this->normaliseLines($lines);
        $context = $this->context($companyId, $branchId, $normalisedLines, $now, $staff, $orderType);

        // Every line's own product first (the refusal order callers know).
        foreach ($normalisedLines as $line) {
            $reason = $this->availability($context, $context['products']->get($line['product_id']));
            if ($reason !== null) {
                throw $reason === QrProductAvailability::SOLD_OUT
                    ? new QrCatalogueException(QrCatalogueException::PRODUCT_SOLD_OUT, 'This product is sold out.')
                    : new QrCatalogueException(QrCatalogueException::PRODUCT_UNAVAILABLE, 'This product is not available.');
            }
        }

        $pricingLines = [];
        $resolvedLines = [];
        foreach ($normalisedLines as $line) {
            $resolved = $this->resolveLine($context, $line);
            if (is_array($resolved)) {
                throw $this->refusal($resolved['reason']);
            }
            $pricingLines[] = new PricingLine(
                unitPriceBaisas: $resolved->unitPriceBaisas,
                qty: $resolved->qty,
                productId: $resolved->isMeal() ? null : (int) $resolved->product->id,
                categoryId: ! $resolved->isMeal() && $resolved->product->category_id !== null ? (int) $resolved->product->category_id : null,
            );
            $resolvedLines[] = $resolved;
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
     * @param  bool  $staff  LAUNCH-P4 M5: the staff set (every in-store
     *                       product), not the customer QR menu
     * @return array{priceable: list<array<string, mixed>>, held: list<array{line_index: int, product_id: int, addon_id: ?int, reason: string}>}
     */
    public function classify(int $companyId, int $branchId, array $lines, ?DateTimeImmutable $now = null, bool $staff = false, ?string $orderType = null): array
    {
        $now = BusinessClock::local($now);
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
        $context = $this->context($companyId, $branchId, array_values($normalised), $now, $staff, $orderType);
        $priceable = [];
        foreach ($normalised as $index => $line) {
            $product = $context['products']->get($line['product_id']);
            $reason = $product === null ? 'product_missing' : $this->availability($context, $product);
            $unavailableAddon = null;
            if ($reason === null) {
                $resolved = $this->resolveLine($context, $line);
                if (is_array($resolved)) {
                    $reason = $resolved['reason'];
                    $unavailableAddon = $resolved['addon_id'];
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
     * Everything the lines name, read once: line products (the customer or
     * staff set), the combos' and meals' lines, the items they may serve
     * (any non-internal standard product of the branch catalogue — a side
     * sold only inside a meal included), branch rows, sold-out switches,
     * add-on groups and add-ons with their availability.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function context(int $companyId, int $branchId, array $lines, DateTimeImmutable $now, bool $staff, ?string $orderType = null): array
    {
        $lineProductIds = array_values(array_unique(array_column($lines, 'product_id')));
        $mealIds = array_values(array_unique(array_filter(array_column($lines, 'meal_id'), static fn ($id): bool => $id !== null)));
        $componentProductIds = [];
        $addonIds = [];
        foreach ($lines as $line) {
            array_push($addonIds, ...$line['addon_ids']);
            foreach ($line['combo'] as $component) {
                $componentProductIds[] = $component['product_id'];
                array_push($addonIds, ...$component['addon_ids']);
            }
        }
        $addonIds = array_values(array_unique($addonIds));

        $query = $staff ? $this->products->forStaff($companyId, $branchId) : $this->products->forBranch($companyId, $branchId);
        $products = $query->whereIn('pos_products.id', $lineProductIds === [] ? [0] : $lineProductIds)->get()->keyBy('id');
        $comboIds = $products->filter(static fn (Product $p): bool => $p->isCombo())->keys()->map(static fn ($id): int => (int) $id)->all();
        $meals = ComboLines::meals($companyId, null, $mealIds);
        $comboLines = ComboLines::load($comboIds, $meals->keys()->map(static fn ($id): int => (int) $id)->all());
        $references = ComboLines::references($comboLines['combos']->flatten(1)->merge($comboLines['meals']->flatten(1)));
        $componentProductIds = array_values(array_unique(array_merge($componentProductIds, $references['products'])));
        $choices = $this->products->soldByBranch($companyId, $branchId)->where('is_internal', false)
            ->where('product_type', '<>', Product::TYPE_COMBO)
            ->whereIn('pos_products.id', $componentProductIds === [] ? [0] : $componentProductIds)->get()->keyBy('id');
        $allIds = array_values(array_unique(array_merge($lineProductIds, $componentProductIds)));
        $branchProducts = BranchProduct::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $allIds === [] ? [0] : $allIds)->get()->keyBy('product_id');
        $groupProducts = $products->reject(static fn (Product $p): bool => $p->isCombo())->union($choices);
        $addons = AddOn::query()->where('company_id', $companyId)->where('status', 'active')
            ->whereIn('id', $addonIds === [] ? [0] : $addonIds)->get()->keyBy('id');

        return [
            'now' => $now,
            'products' => $products,
            'choices' => $choices,
            'branch_products' => $branchProducts,
            'sold_out' => BranchCatalogue::soldOutAt($branchId, $allIds),
            'groups' => $this->applicableGroups($companyId, $groupProducts),
            'addons' => $addons,
            // Fix order PK-A1 (L3) — option lines "Used for" the order's type only.
            'addon_availability' => $this->addonAvailability->handle($companyId, $branchId, $addons->values(), $now, $orderType),
            'combo_lines' => $comboLines['combos'],
            'meal_lines' => $comboLines['meals'],
            'meals' => $meals,
        ];
    }

    /** @param array<string, mixed> $context */
    private function availability(array $context, ?Product $product): ?string
    {
        if ($product === null) {
            return 'product_missing';
        }

        return QrProductAvailability::evaluate(
            $product,
            $context['branch_products']->get($product->id),
            $context['now'],
            isset($context['sold_out'][(int) $product->id]),
        )->reason;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $line
     * @return QrResolvedLine|array{reason: string, addon_id: int|null}
     */
    private function resolveLine(array $context, array $line): QrResolvedLine|array
    {
        /** @var Product $product */
        $product = $context['products']->get($line['product_id']);
        $addons = $this->resolveAddons($context, $product, $line['addon_ids']);
        if (isset($addons['reason'])) {
            return $addons;
        }

        $components = [];
        $meal = null;
        $mealPriceBaisas = 0;
        if ($line['meal_id'] !== null) {
            // LAUNCH combo add-on — "Make it a meal?": an active meal on sale
            // today whose mains include this product.
            $row = $context['meals']->get($line['meal_id']);
            if ($row === null || $product->isCombo()
                || ! SaleDates::covers($row->on_sale_from, $row->on_sale_until, SaleDates::day($context['now']))
                || ! ComboLines::isMainOf($row, $product)) {
                return ['reason' => QrCatalogueException::COMBO_INVALID, 'addon_id' => null];
            }
            $meal = new QrResolvedMeal((int) $row->id, (string) $row->name, $row->name_ar, Money::toBaisas($row->meal_price));
            $mealPriceBaisas = $meal->mealPriceBaisas;
            $components = $this->resolveComboLines($context, $context['meal_lines']->get($meal->id, collect()), $line['combo']);
        } elseif ($product->isCombo()) {
            $components = $this->resolveComboLines($context, $context['combo_lines']->get((int) $product->id, collect()), $line['combo']);
        } elseif ($line['combo'] !== []) {
            return ['reason' => QrCatalogueException::COMBO_INVALID, 'addon_id' => null];
        }
        if (isset($components['reason'])) {
            return $components;
        }

        $basePriceBaisas = Money::toBaisas($product->base_price);
        // Owner decision 7 — a line never goes below 0 (a Remove option may).
        $unitPriceBaisas = max(0, $basePriceBaisas + $mealPriceBaisas
            + array_sum(array_map(static fn (QrResolvedAddOn $resolved): int => $resolved->priceDeltaBaisas, $addons))
            + array_sum(array_map(static fn (QrResolvedComponent $component): int => $component->priceBaisas(), $components)));

        return new QrResolvedLine(
            product: $product,
            qty: $line['qty'],
            notes: $line['notes'],
            basePriceBaisas: $basePriceBaisas,
            unitPriceBaisas: $unitPriceBaisas,
            addons: $addons,
            components: $components,
            meal: $meal,
        );
    }

    /**
     * The add-ons chosen for $product, against its own groups' rules. A
     * combo line has no groups of its own (its items carry the add-ons).
     *
     * @param  array<string, mixed>  $context
     * @param  list<int>  $addonIds
     * @return list<QrResolvedAddOn>|array{reason: string, addon_id: int|null}
     */
    private function resolveAddons(array $context, Product $product, array $addonIds): array
    {
        /** @var Collection<int, AddOnGroup> $groups */
        $groups = $product->isCombo() ? collect() : $context['groups']->get($product->id, collect());
        $groupSet = array_fill_keys($groups->pluck('id')->map(static fn ($id): int => (int) $id)->all(), true);
        $resolved = [];
        $selectedByGroup = [];
        foreach ($addonIds as $addonId) {
            /** @var AddOn|null $addon */
            $addon = $context['addons']->get($addonId);
            if ($addon === null
                || ! isset($groupSet[(int) $addon->add_on_group_id])
                || ! $context['addon_availability']->get((int) $addon->id, ['available' => false])['available']) {
                return ['reason' => QrCatalogueException::ADDON_UNAVAILABLE, 'addon_id' => $addonId];
            }
            $groupId = (int) $addon->add_on_group_id;
            $selectedByGroup[$groupId] = ($selectedByGroup[$groupId] ?? 0) + 1;
            $resolved[] = new QrResolvedAddOn(addon: $addon, priceDeltaBaisas: Money::toBaisas($addon->price_delta));
        }

        foreach ($groups as $group) {
            $selected = $selectedByGroup[(int) $group->id] ?? 0;
            $minimum = $group->min_selections !== null ? (int) $group->min_selections : 0;
            $maximum = $group->max_selections !== null
                ? (int) $group->max_selections
                : ((string) $group->selection_mode === 'single' ? 1 : null);
            if ($selected < $minimum || ($maximum !== null && $selected > $maximum)) {
                return ['reason' => QrCatalogueException::ADDON_SELECTION_INVALID, 'addon_id' => null];
            }
        }

        return $resolved;
    }

    /**
     * LAUNCH combo add-on — the items of a combo or meal, line by line in
     * the lines' order: a fixed line's product or upgrades adding up to its
     * quantity (none sent = the product as is), a choice line's offered items
     * adding up to pick N exactly; every item orderable here, its add-ons
     * valid for it. Any entry naming another line is refused.
     *
     * @param  array<string, mixed>  $context
     * @param  Collection<int, object>  $lines
     * @param  list<array<string, mixed>>  $entries
     * @return list<QrResolvedComponent>|array{reason: string, addon_id: int|null}
     */
    private function resolveComboLines(array $context, Collection $lines, array $entries): array
    {
        $invalid = ['reason' => QrCatalogueException::COMBO_INVALID, 'addon_id' => null];
        $byId = $lines->keyBy('id');
        $grouped = [];
        foreach ($entries as $entry) {
            if (! $byId->has($entry['line_id'])) {
                return $invalid;
            }
            $grouped[$entry['line_id']][] = $entry;
        }

        $components = [];
        foreach ($lines as $line) {
            $picks = $grouped[$line->id] ?? [];
            if ($line->kind === ComboLines::FIXED && $picks === []) {
                $picks = [['line_id' => $line->id, 'product_id' => $line->product_id, 'qty' => $line->quantity, 'addon_ids' => [], 'notes' => '', 'filled' => true]];
            }
            $wanted = $line->kind === ComboLines::FIXED ? $line->quantity : $line->pick_count;
            if (array_sum(array_column($picks, 'qty')) !== $wanted) {
                return $invalid;
            }
            foreach ($picks as $pick) {
                /** @var Product|null $item */
                $item = $context['choices']->get($pick['product_id']);
                if ($item === null) {
                    return $invalid;
                }
                if ($line->kind === ComboLines::FIXED) {
                    $upgrade = (int) $item->id === $line->product_id ? null : ComboLines::upgradeFor($line, (int) $item->id);
                    if ((int) $item->id !== $line->product_id && $upgrade === null) {
                        return $invalid;
                    }
                    $kind = $upgrade === null ? ComboLines::KIND_FIXED : ComboLines::KIND_UPGRADE;
                    $extra = $upgrade === null ? 0 : Money::toBaisas($upgrade->upgrade_price);
                } else {
                    if (! ComboLines::choiceOffers($line, $item)) {
                        return $invalid;
                    }
                    $kind = ComboLines::KIND_CHOICE;
                    $extra = ComboLines::choiceExtraBaisas($line, (int) $item->id);
                }
                $reason = $this->availability($context, $item);
                if ($reason !== null) {
                    return ['reason' => $reason, 'addon_id' => null];
                }
                $addons = $this->resolveAddons($context, $item, $pick['addon_ids']);
                if (isset($addons['reason'])) {
                    return $addons;
                }
                $components[] = new QrResolvedComponent(
                    lineId: $line->id,
                    kind: $kind,
                    lineName: $line->kind === ComboLines::CHOICE ? (string) $line->name : null,
                    lineNameAr: $line->kind === ComboLines::CHOICE ? $line->name_ar : null,
                    product: $item,
                    qty: $pick['qty'],
                    extraPriceBaisas: $extra,
                    notes: $pick['notes'],
                    addons: $addons,
                    filled: (bool) ($pick['filled'] ?? false),
                );
            }
        }

        return $components;
    }

    private function refusal(string $reason): QrCatalogueException
    {
        return match ($reason) {
            QrCatalogueException::ADDON_UNAVAILABLE => new QrCatalogueException($reason, 'This add-on is not available.'),
            QrCatalogueException::ADDON_SELECTION_INVALID => new QrCatalogueException($reason, 'The selected add-ons are invalid.'),
            QrCatalogueException::COMBO_INVALID => new QrCatalogueException($reason, 'The combo or meal items are invalid.'),
            QrProductAvailability::SOLD_OUT => new QrCatalogueException(QrCatalogueException::PRODUCT_SOLD_OUT, 'An item of the combo or meal is sold out.'),
            default => new QrCatalogueException(QrCatalogueException::PRODUCT_UNAVAILABLE, 'This product is not available.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id: int, qty: int, addon_ids: list<int>, notes: string, meal_id: int|null, combo: list<array{line_id: int, product_id: int, qty: int, addon_ids: list<int>, notes: string}>}>
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
                || ($line['notes'] !== null && ! is_string($line['notes']))
                || (($line['meal_id'] ?? null) !== null && (! is_int($line['meal_id']) || $line['meal_id'] < 1))) {
                throw new QrCatalogueException(QrCatalogueException::INVALID_LINE, 'The order lines are invalid.');
            }
            $normalised[] = [
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'addon_ids' => $this->addonIds($line['addon_ids']),
                'notes' => OneLineNote::cut($line['notes']) ?? '',
                // LAUNCH combo add-on — "Make it a meal?" on this (main) product.
                'meal_id' => $line['meal_id'] ?? null,
                'combo' => $this->normaliseCombo($line['combo'] ?? null),
            ];
        }

        return $normalised;
    }

    /**
     * LAUNCH combo add-on — a combo or meal line's items, per ONE combo /
     * meal: {line_id, product_id, qty, addon_ids | addons, notes}. Add-ons
     * come as `addon_ids` (QR, tablet) or the device wire's `addons:
     * [{add_on_id, ...}]`; any price, kind or allocation a device sends
     * inside is ignored (the server prices). The old `slot_id` is refused.
     *
     * @return list<array{line_id: int, product_id: int, qty: int, addon_ids: list<int>, notes: string}>
     */
    private function normaliseCombo(mixed $combo): array
    {
        if ($combo === null) {
            return [];
        }
        if (! is_array($combo) || ! array_is_list($combo) || count($combo) > 50) {
            throw new QrCatalogueException(QrCatalogueException::INVALID_LINE, 'The order lines are invalid.');
        }
        $choices = [];
        foreach ($combo as $choice) {
            $qty = $choice['qty'] ?? 1;
            $notes = $choice['notes'] ?? null;
            $addonIds = $choice['addon_ids'] ?? (is_array($choice['addons'] ?? null)
                ? array_map(static fn (mixed $addon): mixed => is_array($addon) ? ($addon['add_on_id'] ?? null) : null, $choice['addons'])
                : []);
            if (! is_array($choice)
                || ! is_int($choice['line_id'] ?? null) || $choice['line_id'] < 1
                || ! is_int($choice['product_id'] ?? null) || $choice['product_id'] < 1
                || ! is_int($qty) || $qty < 1 || $qty > 99
                || ! is_array($addonIds)
                || ($notes !== null && ! is_string($notes))) {
                throw new QrCatalogueException(QrCatalogueException::INVALID_LINE, 'The order lines are invalid.');
            }
            $choices[] = [
                'line_id' => $choice['line_id'],
                'product_id' => $choice['product_id'],
                'qty' => $qty,
                'addon_ids' => $this->addonIds($addonIds),
                'notes' => OneLineNote::cut($notes) ?? '',
            ];
        }

        return $choices;
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private function addonIds(array $ids): array
    {
        $addonIds = [];
        foreach (array_values($ids) as $addonId) {
            if (! is_int($addonId) || $addonId < 1 || in_array($addonId, $addonIds, true)) {
                throw new QrCatalogueException(
                    QrCatalogueException::ADDON_SELECTION_INVALID,
                    'The selected add-ons are invalid.',
                );
            }
            $addonIds[] = $addonId;
        }

        return $addonIds;
    }

    /** @param Collection<int, Product> $products @return Collection<int, Collection<int, AddOnGroup>> */
    private function applicableGroups(int $companyId, Collection $products): Collection
    {
        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $categoryIds = $products->pluck('category_id')->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $productBindings = DB::table('pos_addon_group_products')->whereIn('product_id', $productIds === [] ? [0] : $productIds)
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
