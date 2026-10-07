<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH combo add-on (LAUNCH-COMBO_WORK_ORDER.md §1, owner decisions
 * 2026-10-07) — combos and meals as lists of LINES, read in one place for the
 * device config, the QR / tablet menu, the pricer and the pricing check.
 *
 *   fixed line   product_id × quantity, always included, never asked; may
 *                offer UPGRADES (another real product at an upgrade price)
 *   choice line  "pick pick_count from category_id" (repeats allowed): every
 *                standard, non-internal product of the category, live (new
 *                products join automatically), minus the ones the merchant
 *                unticked (pos_combo_line_items.excluded); each free unless
 *                its row sets an extra price
 *
 * A meal ("Make it a meal?") applies to the mains of its categories minus the
 * unticked ones; a product is the main of at most ONE active meal (the
 * portal's clash rule — when two slipped in, the lowest sort / id wins here).
 */
final class ComboLines
{
    public const FIXED = 'fixed';

    public const CHOICE = 'choice';

    /** Order-line child kinds (pos_order_items.combo_child_kind). */
    public const KIND_FIXED = 'fixed';

    public const KIND_UPGRADE = 'upgrade';

    public const KIND_CHOICE = 'choice';

    public const KIND_MAIN = 'main';

    public const KINDS = [self::KIND_FIXED, self::KIND_UPGRADE, self::KIND_CHOICE, self::KIND_MAIN];

    /**
     * The lines of the given combos and meals, in sort order, each with its
     * `upgrades` (ordered, keyed by product id) and `items` (the choice
     * overrides, keyed by product id).
     *
     * @param  list<int>  $comboIds
     * @param  list<int>  $mealIds
     * @return array{combos: Collection<int, Collection<int, object>>, meals: Collection<int, Collection<int, object>>}
     */
    public static function load(array $comboIds, array $mealIds = []): array
    {
        $lines = DB::table('pos_combo_lines')
            ->where(function ($query) use ($comboIds, $mealIds): void {
                $query->whereIn('combo_product_id', $comboIds === [] ? [0] : $comboIds)
                    ->orWhereIn('meal_id', $mealIds === [] ? [0] : $mealIds);
            })
            ->orderBy('sort_order')->orderBy('id')->get();
        // Tenancy (defence in depth; the portal and pos:check-tenant-integrity
        // already keep it): a line whose product or category belongs to
        // another merchant than the line is left out, and so is an upgrade or
        // override naming another merchant's product.
        $productCompany = DB::table('pos_products')->whereIn('id', $lines->pluck('product_id')->filter()->all() ?: [0])->pluck('company_id', 'id');
        $categoryCompany = DB::table('pos_product_categories')->whereIn('id', $lines->pluck('category_id')->filter()->all() ?: [0])->pluck('company_id', 'id');
        $lines = $lines->filter(static fn (object $line): bool => ($line->product_id === null || (int) ($productCompany[$line->product_id] ?? 0) === (int) $line->company_id)
            && ($line->category_id === null || (int) ($categoryCompany[$line->category_id] ?? 0) === (int) $line->company_id))->values();
        $lineIds = $lines->pluck('id')->all();
        $lineCompany = $lines->pluck('company_id', 'id');
        $ownRow = static function (object $row) use ($lineCompany): bool {
            return (int) ($row->product_company ?? 0) === (int) ($lineCompany[$row->line_id] ?? -1);
        };
        $upgrades = DB::table('pos_combo_line_upgrades')->join('pos_products', 'pos_products.id', '=', 'pos_combo_line_upgrades.product_id')
            ->whereIn('pos_combo_line_upgrades.line_id', $lineIds === [] ? [0] : $lineIds)
            ->orderBy('pos_combo_line_upgrades.sort_order')->orderBy('pos_combo_line_upgrades.id')
            ->get(['pos_combo_line_upgrades.*', 'pos_products.company_id as product_company'])->filter($ownRow)->groupBy('line_id');
        $items = DB::table('pos_combo_line_items')->join('pos_products', 'pos_products.id', '=', 'pos_combo_line_items.product_id')
            ->whereIn('pos_combo_line_items.line_id', $lineIds === [] ? [0] : $lineIds)->orderBy('pos_combo_line_items.id')
            ->get(['pos_combo_line_items.*', 'pos_products.company_id as product_company'])->filter($ownRow)->groupBy('line_id');

        foreach ($lines as $line) {
            $line->id = (int) $line->id;
            $line->sort_order = (int) $line->sort_order;
            $line->product_id = $line->product_id !== null ? (int) $line->product_id : null;
            $line->category_id = $line->category_id !== null ? (int) $line->category_id : null;
            $line->quantity = $line->quantity !== null ? (int) $line->quantity : null;
            $line->pick_count = $line->pick_count !== null ? (int) $line->pick_count : null;
            $line->upgrades = collect($upgrades->get($line->id) ?? [])->keyBy(static fn (object $row): int => (int) $row->product_id);
            $line->items = collect($items->get($line->id) ?? [])->keyBy(static fn (object $row): int => (int) $row->product_id);
        }

        return [
            'combos' => $lines->whereNotNull('combo_product_id')->groupBy(static fn (object $line): int => (int) $line->combo_product_id),
            'meals' => $lines->whereNotNull('meal_id')->groupBy(static fn (object $line): int => (int) $line->meal_id),
        ];
    }

    /**
     * The merchant's meals (active, not deleted, and on sale on $day when
     * given; optionally only $ids), in sort order, each with `categories`
     * and `excluded` (product ids).
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, object>
     */
    public static function meals(int $companyId, ?string $day, ?array $ids = null, bool $activeOnly = true): Collection
    {
        $query = DB::table('pos_meals')->where('company_id', $companyId)->whereNull('deleted_at');
        if ($activeOnly) {
            $query->where('status', 'active');
        }
        if ($ids !== null) {
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }
        $meals = $query->orderBy('sort_order')->orderBy('id')->get();
        if ($day !== null) {
            $meals = $meals->filter(static fn (object $meal): bool => SaleDates::covers($meal->on_sale_from, $meal->on_sale_until, $day))->values();
        }
        $mealIds = $meals->pluck('id')->all();
        $categories = DB::table('pos_meal_categories')->whereIn('meal_id', $mealIds === [] ? [0] : $mealIds)
            ->orderBy('id')->get()->groupBy('meal_id');
        $excluded = DB::table('pos_meal_excluded_products')->whereIn('meal_id', $mealIds === [] ? [0] : $mealIds)
            ->orderBy('id')->get()->groupBy('meal_id');
        foreach ($meals as $meal) {
            $meal->id = (int) $meal->id;
            $meal->categories = collect($categories->get($meal->id) ?? [])->pluck('category_id')->map(static fn ($id): int => (int) $id)->values()->all();
            $meal->excluded = collect($excluded->get($meal->id) ?? [])->pluck('product_id')->map(static fn ($id): int => (int) $id)->values()->all();
        }

        return $meals->keyBy('id');
    }

    /** Whether $product is a main of $meal (a standard product of one of its categories, not unticked). */
    public static function isMainOf(object $meal, object $product): bool
    {
        return (string) ($product->product_type ?? Product::TYPE_STANDARD) === Product::TYPE_STANDARD
            && ! (bool) ($product->is_internal ?? false)
            && $product->category_id !== null
            && in_array((int) $product->category_id, $meal->categories, true)
            && ! in_array((int) $product->id, $meal->excluded, true);
    }

    /**
     * The meal $product is the main of (the first by sort order, id), or null.
     *
     * @param  Collection<int, object>  $meals
     */
    public static function mealFor(Collection $meals, object $product): ?object
    {
        return $meals->first(static fn (object $meal): bool => self::isMainOf($meal, $product));
    }

    /** Whether a choice line offers $product (its category, not unticked, a standard menu product). */
    public static function choiceOffers(object $line, object $product): bool
    {
        return $line->kind === self::CHOICE
            && (string) ($product->product_type ?? Product::TYPE_STANDARD) === Product::TYPE_STANDARD
            && ! (bool) ($product->is_internal ?? false)
            && $product->category_id !== null && (int) $product->category_id === $line->category_id
            && ! (bool) ($line->items->get((int) $product->id)?->excluded ?? false);
    }

    /** A choice item's extra price in baisas (0 = free). */
    public static function choiceExtraBaisas(object $line, int $productId): int
    {
        $row = $line->items->get($productId);

        return $row === null ? 0 : Money::toBaisas($row->extra_price);
    }

    /** A fixed line's upgrade to $productId, or null. */
    public static function upgradeFor(object $line, int $productId): ?object
    {
        return $line->kind === self::FIXED ? $line->upgrades->get($productId) : null;
    }

    /**
     * Every product id the lines name directly (fixed products, upgrades and
     * choice overrides) and the choice categories.
     *
     * @param  iterable<object>  $lines
     * @return array{products: list<int>, categories: list<int>}
     */
    public static function references(iterable $lines): array
    {
        $products = [];
        $categories = [];
        foreach ($lines as $line) {
            if ($line->product_id !== null) {
                $products[] = $line->product_id;
            }
            foreach ($line->upgrades->keys() as $id) {
                $products[] = (int) $id;
            }
            if ($line->category_id !== null) {
                $categories[] = $line->category_id;
            }
        }

        return ['products' => array_values(array_unique($products)), 'categories' => array_values(array_unique($categories))];
    }
}
