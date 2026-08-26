<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/** The one visibility predicate shared by the QR menu and authoritative pricer. */
final class QrBranchProductQuery
{
    /** @return Builder<Product> */
    public function forBranch(int $companyId, int $branchId): Builder
    {
        return $this->soldByBranch($companyId, $branchId)
            ->where('is_internal', false)
            ->where('show_on_customer_tablet', true);
    }

    /**
     * Products sold by a branch, before standalone customer-menu visibility.
     *
     * Linked add-ons may intentionally point at an internal or tablet-hidden
     * product, so their stock gate uses this base predicate.
     *
     * @return Builder<Product>
     */
    public function soldByBranch(int $companyId, int $branchId): Builder
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereExists(function ($subquery) use ($branchId): void {
                    $subquery->selectRaw('1')
                        ->from('pos_branch_product')
                        ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                        ->where('pos_branch_product.branch_id', $branchId);
                })->orWhereNotExists(function ($subquery): void {
                    $subquery->selectRaw('1')
                        ->from('pos_branch_product')
                        ->whereColumn('pos_branch_product.product_id', 'pos_products.id');
                });
            });
    }
}
