<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;
use App\Support\Catalogue\BranchCatalogue;
use Illuminate\Database\Eloquent\Builder;

/** The one visibility predicate shared by the QR menu and authoritative pricer. */
final class QrBranchProductQuery
{
    /**
     * The customer QR menu: in-store products (LAUNCH-P4 channels: QR is an
     * in-store channel) shown on the QR menu, never internal items.
     *
     * @return Builder<Product>
     */
    public function forBranch(int $companyId, int $branchId): Builder
    {
        return $this->forStaff($companyId, $branchId)
            ->where('show_on_customer_tablet', true);
    }

    /**
     * LAUNCH-P4 M5 — what staff may put on a table round: every in-store
     * product of the branch, the QR menu switch aside, never internal items.
     *
     * @return Builder<Product>
     */
    public function forStaff(int $companyId, int $branchId): Builder
    {
        return $this->soldByBranch($companyId, $branchId)
            ->where('is_internal', false)
            ->where('sold_in_store', true);
    }

    /**
     * Products in a branch's catalogue, before channel and customer-menu
     * visibility (LAUNCH-P4 branch scope: {@see BranchCatalogue}). A product
     * switched off at this branch stays in the set so QR can show it greyed.
     *
     * Linked add-ons may intentionally point at an internal, tablet-hidden or
     * add-on-only product, so their stock gate uses this base predicate.
     *
     * @return Builder<Product>
     */
    public function soldByBranch(int $companyId, int $branchId): Builder
    {
        return BranchCatalogue::inCatalogueOf(Product::query()->where('company_id', $companyId), $branchId);
    }
}
