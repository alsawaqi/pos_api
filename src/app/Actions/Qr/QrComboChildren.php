<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Support\Catalogue\ComboLines;
use App\Support\Catalogue\CookingTime;
use App\Support\Money;
use App\Support\Pricing\ComboAllocation;
use Carbon\CarbonInterface;

/**
 * LAUNCH combo add-on (tester call 3) — the rows of a server-priced combo or
 * meal line (QR checkout, QR and staff table rounds, quick-order additions,
 * tablet orders):
 *
 *   the PARENT carries the money (unit price, line total, discounts). A
 *   combo parent is the combo product; a MEAL parent has no product_id, its
 *   meal_id, the name "<main> <meal>", no recipe and no components (it
 *   takes nothing from stock) and no add-ons (they are the main's);
 *
 *   one CHILD per item: a meal's main first (kind 'main', with the main's
 *   add-ons and notes), then the lines' items in order (kind 'fixed',
 *   'upgrade' or 'choice', the line id as combo_line_id, the extra / upgrade
 *   price per item as combo_extra_price): product_id = the item served, qty =
 *   line qty × item qty, unit_price_snapshot = 0 and line_total = 0, its own
 *   recipe / component copies (stock follows the product actually served,
 *   a Remove option leaves its ingredient out) and its own add-on rows; and
 *   allocated_revenue_baisas, its share of the parent's line total by the
 *   items' normal prices ({@see ComboAllocation}).
 *
 * Built once as a private payload (so a staff-confirmed round appends
 * exactly what was priced) and written under the parent line.
 */
final class QrComboChildren
{
    public function __construct(private readonly OrderLineSnapshotter $snapshots) {}

    /**
     * @return list<array{attributes: array<string, mixed>, addons: list<array<string, mixed>>}>
     */
    public function payload(QrResolvedLine $line, int $companyId, ?CarbonInterface $recipeAt = null, int $lineDiscountBaisas = 0): array
    {
        if (! $line->hasChildren()) {
            return [];
        }
        $items = [];
        if ($line->meal !== null) {
            $items[] = ['kind' => ComboLines::KIND_MAIN, 'line_id' => null, 'product' => $line->product, 'qty' => 1,
                'extra' => 0, 'notes' => $line->notes, 'addons' => $line->addons];
        }
        foreach ($line->components as $component) {
            $items[] = ['kind' => $component->kind, 'line_id' => $component->lineId, 'product' => $component->product,
                'qty' => $component->qty, 'extra' => $component->extraPriceBaisas, 'notes' => $component->notes, 'addons' => $component->addons];
        }
        // Fix order 1 (tester call 3) — the split base is what the line paid
        // after its own line discount; the weights are the in-store prices
        // (QR, tablet and table rounds are never delivery orders).
        $shares = ComboAllocation::forLine(
            $line->unitPriceBaisas,
            $line->qty,
            max(0, $line->unitPriceBaisas * $line->qty - $lineDiscountBaisas),
            array_map(static fn (array $item): array => ['weight' => Money::toBaisas($item['product']->base_price), 'qty' => $item['qty']], $items),
        );

        $children = [];
        foreach ($items as $index => $item) {
            /** @var Product $product */
            $product = $item['product'];
            /** @var list<QrResolvedAddOn> $resolvedAddons */
            $resolvedAddons = $item['addons'];
            $addonIds = array_map(static fn (QrResolvedAddOn $resolved): int => (int) $resolved->addon->id, $resolvedAddons);
            $snapshots = $this->snapshots->product($product, $recipeAt, $this->snapshots->removedIngredientIds($companyId, $addonIds));
            $addons = [];
            foreach ($resolvedAddons as $resolvedAddon) {
                $addons[] = [
                    'add_on_id' => (int) $resolvedAddon->addon->id,
                    'add_on_name_snapshot' => (string) $resolvedAddon->addon->name,
                    'price_delta_snapshot' => Money::toOmr($resolvedAddon->priceDeltaBaisas),
                ] + $this->snapshots->addon($resolvedAddon->addon, $companyId, $recipeAt);
            }
            $children[] = [
                'attributes' => [
                    'product_id' => (int) $product->id,
                    'product_name_snapshot' => (string) $product->name,
                    'qty' => $line->qty * $item['qty'],
                    'unit_price_snapshot' => Money::toOmr(0),
                    'line_discount' => Money::toOmr(0),
                    'line_total' => Money::toOmr(0),
                    'recipe_snapshot_json' => $snapshots['recipe_snapshot_json'],
                    'component_snapshot_json' => $snapshots['component_snapshot_json'],
                    'status' => OrderItem::STATUS_OPEN,
                    'notes' => $item['notes'] !== '' ? $item['notes'] : null,
                    'combo_line_id' => $item['line_id'],
                    'combo_child_kind' => $item['kind'],
                    'combo_extra_price' => Money::toOmr($item['extra']),
                    'allocated_revenue_baisas' => $shares[$index] ?? 0,
                    // LAUNCH review add-on — the server's cooking-time snapshot.
                    'cooking_minutes' => CookingTime::of($product),
                ],
                'addons' => $addons,
            ];
        }

        return $children;
    }

    /**
     * The parent row's product fields: a meal parent has no product (its
     * main is a child), the meal's id and "<main> <meal>" as its name, no
     * recipe or components and no add-ons; any other line is its product.
     *
     * @param  array{recipe_snapshot_json: mixed, component_snapshot_json: mixed}  $productSnapshots
     * @param  list<array{attributes: array<string, mixed>, addons: list<array<string, mixed>>}>  $children
     * @return array<string, mixed>
     */
    public static function parentAttributes(QrResolvedLine $line, array $productSnapshots, array $children): array
    {
        if ($line->meal !== null) {
            return [
                'product_id' => null,
                'meal_id' => $line->meal->id,
                'product_name_snapshot' => $line->displayName(),
                'recipe_snapshot_json' => null,
                'component_snapshot_json' => [],
                'notes' => null,
                'cooking_minutes' => self::parentCookingMinutes(null, $children),
            ];
        }

        return [
            'product_id' => (int) $line->product->id,
            'product_name_snapshot' => (string) $line->product->name,
            'recipe_snapshot_json' => $productSnapshots['recipe_snapshot_json'],
            'component_snapshot_json' => $productSnapshots['component_snapshot_json'],
            'notes' => $line->notes !== '' ? $line->notes : null,
            'cooking_minutes' => self::parentCookingMinutes($line->product, $children),
        ];
    }

    /** The add-ons written on the PARENT row (a meal's are the main child's). @return list<QrResolvedAddOn> */
    public static function parentAddons(QrResolvedLine $line): array
    {
        return $line->meal !== null ? [] : $line->addons;
    }

    /**
     * LAUNCH review add-on — a combo or meal parent's cooking-time snapshot:
     * its longest child, else the combo's own value. A payload frozen before
     * the add-on has no child values (treated as none).
     *
     * @param  list<array{attributes: array<string, mixed>, addons: list<array<string, mixed>>}>  $children
     */
    public static function parentCookingMinutes(?Product $combo, array $children): ?int
    {
        return CookingTime::forLine($combo, array_map(
            static fn (array $child): ?int => isset($child['attributes']['cooking_minutes']) ? (int) $child['attributes']['cooking_minutes'] : null,
            $children,
        ));
    }

    /**
     * Write the children payload under $parent (same order).
     *
     * @param  list<array{attributes: array<string, mixed>, addons: list<array<string, mixed>>}>  $children
     */
    public function write(OrderItem $parent, array $children): void
    {
        foreach ($children as $child) {
            $item = OrderItem::query()->create([
                'order_id' => $parent->order_id,
                'parent_order_item_id' => $parent->id,
            ] + $child['attributes']);
            foreach ($child['addons'] as $addon) {
                OrderItemAddon::query()->create(['order_item_id' => $item->id] + $addon);
            }
        }
    }
}
