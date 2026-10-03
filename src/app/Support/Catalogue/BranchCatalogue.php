<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 — where a product is sold, and whether a branch marked it sold
 * out (data contract; owner decisions 4 and 8). One rule for the device
 * config, QR, table rounds and production:
 *
 *   branch_scope 'all'      every branch sells it, except a branch whose
 *                           pos_branch_product row says is_available = false
 *   branch_scope 'selected' only branches whose row says is_available = true
 *
 * Stock rows never restrict on their own: a shelf row a stock action wrote
 * is available (true) under 'all', so it changes nothing (H6). Sold out is a
 * pos_product_sold_out row for (branch, product), set by hand on a device or
 * in the portal — never from stock numbers.
 */
final class BranchCatalogue
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    /**
     * Restrict a pos_products query to the products the branch sells.
     *
     * @template TQuery of Builder<Product>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function soldAt(Builder|QueryBuilder $query, int $branchId): Builder|QueryBuilder
    {
        return $query->where(function ($scope) use ($branchId): void {
            $scope->where(function ($all) use ($branchId): void {
                $all->where('pos_products.branch_scope', '<>', self::SCOPE_SELECTED)
                    ->whereNotExists(self::branchRow($branchId, false));
            })->orWhere(function ($selected) use ($branchId): void {
                $selected->where('pos_products.branch_scope', self::SCOPE_SELECTED)
                    ->whereExists(self::branchRow($branchId, true));
            });
        });
    }

    /**
     * The QR menu's base set: products in the branch's catalogue, which also
     * keeps a product switched off at this branch so the menu can show it
     * greyed ("branch unavailable"). A 'selected' product without a row here
     * is not part of this branch's menu at all.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function inCatalogueOf(Builder $query, int $branchId): Builder
    {
        return $query->where(function (Builder $scope) use ($branchId): void {
            $scope->where('pos_products.branch_scope', '<>', self::SCOPE_SELECTED)
                ->orWhereExists(function ($row) use ($branchId): void {
                    $row->selectRaw('1')->from('pos_branch_product')
                        ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                        ->where('pos_branch_product.branch_id', $branchId);
                });
        });
    }

    /** Whether the branch sells $product, given its pos_branch_product row here (or null). */
    public static function availableAt(object $product, ?object $branchRow): bool
    {
        if ((string) ($product->branch_scope ?? self::SCOPE_ALL) === self::SCOPE_SELECTED) {
            return $branchRow !== null && (bool) $branchRow->is_available;
        }

        return $branchRow === null || (bool) $branchRow->is_available;
    }

    /**
     * The availability a stock action gives a branch row it has to CREATE,
     * so stock never restricts and never grants: neutral (true) under 'all',
     * not selected (false) under 'selected'.
     */
    public static function newRowAvailability(object $product): bool
    {
        return (string) ($product->branch_scope ?? self::SCOPE_ALL) !== self::SCOPE_SELECTED;
    }

    /**
     * Product ids sold out at the branch (optionally among $productIds).
     *
     * @param  list<int>|null  $productIds
     * @return array<int, true>
     */
    public static function soldOutAt(int $branchId, ?array $productIds = null): array
    {
        $query = DB::table('pos_product_sold_out')->where('branch_id', $branchId);
        if ($productIds !== null) {
            $query->whereIn('product_id', $productIds === [] ? [0] : $productIds);
        }

        return array_fill_keys($query->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(), true);
    }

    private static function branchRow(int $branchId, bool $available): \Closure
    {
        return static function ($row) use ($branchId, $available): void {
            $row->selectRaw('1')->from('pos_branch_product')
                ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                ->where('pos_branch_product.branch_id', $branchId)
                ->where('pos_branch_product.is_available', $available);
        };
    }
}
