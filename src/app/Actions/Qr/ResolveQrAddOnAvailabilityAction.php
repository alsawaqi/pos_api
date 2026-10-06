<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Recipes\OrderTypes;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves every product-backed add-on against live branch availability.
 *
 * This covers both the legacy linked_product_id and PD3b product consumption
 * lines whose net direction consumes stock. Standalone tablet visibility is
 * deliberately irrelevant: a product may be sold only as an add-on.
 */
final class ResolveQrAddOnAvailabilityAction
{
    public const LINKED_PRODUCT_UNAVAILABLE = 'linked_product_unavailable';

    public const CONSUMPTION_PRODUCT_UNAVAILABLE = 'consumption_product_unavailable';

    public function __construct(private readonly QrBranchProductQuery $products) {}

    /**
     * Fix order PK-A1 (L3) — $orderType: the order type of the QR session
     * (a table = 'dine_in', a quick order = 'quick'). Only the option's
     * product lines "Used for" that type count, so a to-go-only cup never
     * greys an option on a table, and a dine-in-only add is not netted
     * against a to-go-only remove. null = every line (the old rule).
     *
     * @param  Collection<int, AddOn>  $addons
     * @return Collection<int, array{available: bool, reason: string|null}>
     */
    public function handle(
        int $companyId,
        int $branchId,
        Collection $addons,
        DateTimeInterface $at,
        ?string $orderType = null,
    ): Collection {
        if ($addons->isEmpty()) {
            return collect();
        }

        $netConsumption = [];
        $rows = DB::table('pos_addon_consumptions')
            ->whereIn('add_on_id', $addons->pluck('id')->all())
            ->whereNotNull('component_product_id')
            ->orderBy('add_on_id')
            ->orderBy('component_product_id')
            ->orderBy('id')
            ->get();

        $bit = OrderTypes::bit(OrderTypes::bucket($orderType));
        foreach ($rows as $row) {
            if ($bit !== null && (OrderTypes::normalize($row->order_types ?? null) & $bit) === 0) {
                continue;
            }
            $addonId = (int) $row->add_on_id;
            $productId = (int) $row->component_product_id;
            $direction = (string) $row->direction;
            $quantity = (float) $row->quantity;
            $delta = $direction === 'remove' ? -$quantity : $quantity;
            $netConsumption[$addonId][$productId] =
                ($netConsumption[$addonId][$productId] ?? 0.0) + $delta;
        }

        $requiredByAddon = [];
        foreach ($addons as $addon) {
            $required = [];
            if ($addon->linked_product_id !== null) {
                $required[(int) $addon->linked_product_id] = 'linked';
            }
            foreach ($netConsumption[(int) $addon->id] ?? [] as $productId => $netQuantity) {
                if ($netQuantity > 0.0 && ! isset($required[(int) $productId])) {
                    $required[(int) $productId] = 'consumption';
                }
            }
            ksort($required);
            $requiredByAddon[(int) $addon->id] = $required;
        }

        $requiredIds = collect($requiredByAddon)
            ->flatMap(static fn (array $required): array => array_keys($required))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $products = $this->products->soldByBranch($companyId, $branchId)
            ->whereIn('pos_products.id', $requiredIds === [] ? [0] : $requiredIds)
            ->get()
            ->keyBy('id');
        $branchProducts = BranchProduct::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $requiredIds === [] ? [0] : $requiredIds)
            ->get()
            ->keyBy('product_id');
        // LAUNCH-P4 — a product switched to "sold out" here greys the add-ons
        // that sell it, on every channel.
        $soldOut = BranchCatalogue::soldOutAt($branchId, $requiredIds);

        return $addons->mapWithKeys(function (AddOn $addon) use (
            $requiredByAddon,
            $products,
            $branchProducts,
            $soldOut,
            $at,
        ): array {
            foreach ($requiredByAddon[(int) $addon->id] ?? [] as $productId => $kind) {
                /** @var Product|null $product */
                $product = $products->get($productId);
                if ($product === null) {
                    return [(int) $addon->id => [
                        'available' => false,
                        'reason' => $kind === 'linked'
                            ? self::LINKED_PRODUCT_UNAVAILABLE
                            : self::CONSUMPTION_PRODUCT_UNAVAILABLE,
                    ]];
                }

                /** @var BranchProduct|null $branchProduct */
                $branchProduct = $branchProducts->get($productId);
                $availability = QrProductAvailability::evaluate($product, $branchProduct, $at, isset($soldOut[(int) $productId]));
                if (! $availability->available) {
                    return [(int) $addon->id => [
                        'available' => false,
                        'reason' => $availability->reason,
                    ]];
                }
            }

            return [(int) $addon->id => ['available' => true, 'reason' => null]];
        });
    }
}
