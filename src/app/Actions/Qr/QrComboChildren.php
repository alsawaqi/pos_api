<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Support\Catalogue\CookingTime;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * LAUNCH-P4 — the child lines of a server-priced combo line (QR checkout,
 * QR and staff table rounds, quick-order additions), per the data contract:
 *
 *   one child per chosen item: product_id = the item, qty = combo qty ×
 *   choice qty, unit_price_snapshot = 0 and line_total = 0 (the revenue
 *   sits on the parent), its own recipe / component snapshots, the slot it
 *   was chosen in (combo_slot_id) and the option's extra price per item;
 *   its add-on rows keep their price for display.
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
    public function payload(QrResolvedLine $line, int $companyId, ?CarbonInterface $recipeAt = null): array
    {
        $children = [];
        foreach ($line->components as $component) {
            // LAUNCH review add-on — each chosen item's own Remove options
            // leave their ingredients out of that item's recipe copy.
            $snapshots = $this->snapshots->product(
                $component->product,
                $recipeAt,
                $this->snapshots->removedIngredientIds($companyId, $component->addonIds()),
            );
            $addons = [];
            foreach ($component->addons as $resolvedAddon) {
                $addons[] = [
                    'add_on_id' => (int) $resolvedAddon->addon->id,
                    'add_on_name_snapshot' => (string) $resolvedAddon->addon->name,
                    'price_delta_snapshot' => Money::toOmr($resolvedAddon->priceDeltaBaisas),
                ] + $this->snapshots->addon($resolvedAddon->addon, $companyId, $recipeAt);
            }
            $children[] = [
                'attributes' => [
                    'product_id' => (int) $component->product->id,
                    'product_name_snapshot' => (string) $component->product->name,
                    'qty' => $line->qty * $component->qty,
                    'unit_price_snapshot' => Money::toOmr(0),
                    'line_discount' => Money::toOmr(0),
                    'line_total' => Money::toOmr(0),
                    'recipe_snapshot_json' => $snapshots['recipe_snapshot_json'],
                    'component_snapshot_json' => $snapshots['component_snapshot_json'],
                    'status' => OrderItem::STATUS_OPEN,
                    'notes' => $component->notes !== '' ? $component->notes : null,
                    'combo_slot_id' => $component->slotId,
                    'combo_extra_price' => Money::toOmr($component->extraPriceBaisas),
                    // LAUNCH review add-on — the server's cooking-time snapshot.
                    'cooking_minutes' => CookingTime::of($component->product),
                ],
                'addons' => $addons,
            ];
        }

        return $children;
    }

    /**
     * LAUNCH review add-on — a combo parent's cooking-time snapshot: its
     * longest child, else the combo's own value. A payload frozen before the
     * add-on has no child values (treated as none).
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
