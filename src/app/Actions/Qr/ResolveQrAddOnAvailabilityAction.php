<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;
use App\Models\BranchProduct;
use App\Models\Product;
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
     * @param  Collection<int, AddOn>  $addons
     * @return Collection<int, array{available: bool, reason: string|null}>
     */
    public function handle(
        int $companyId,
        int $branchId,
        Collection $addons,
        DateTimeInterface $at,
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

        foreach ($rows as $row) {
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

        return $addons->mapWithKeys(function (AddOn $addon) use (
            $requiredByAddon,
            $products,
            $branchProducts,
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
                $availability = QrProductAvailability::evaluate($product, $branchProduct, $at);
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
