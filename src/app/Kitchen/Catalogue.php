<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\DB;

final class Catalogue
{
    public static function query(int $company, int $branch)
    {
        $categories = DB::table('pos_product_categories')->where('company_id', $company)->whereNull('deleted_at')->get(['id', 'branch_availability_json'])->filter(function ($c) use ($branch) {
            $scope = $c->branch_availability_json === null ? null : json_decode($c->branch_availability_json, true);

            return $scope === null || $scope === [] || (is_array($scope) && in_array($branch, array_map('intval', $scope), true));
        })->pluck('id')->all();

        return DB::table('pos_products')->where('company_id', $company)->whereNull('deleted_at')->where('status', 'active')->where('is_internal', false)
            ->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $categories))
            ->where(function ($scope) use ($branch): void {
                $row = static fn ($query, bool $available) => $query->selectRaw('1')->from('pos_branch_product')->whereColumn('pos_branch_product.product_id', 'pos_products.id')->where('pos_branch_product.branch_id', $branch)->where('pos_branch_product.is_available', $available);
                $scope->where(fn ($all) => $all->where(fn ($kind) => $kind->whereNull('branch_scope')->orWhere('branch_scope', 'all'))->whereNotExists(fn ($q) => $row($q, false)))
                    ->orWhere(fn ($selected) => $selected->where('branch_scope', 'selected')->whereExists(fn ($q) => $row($q, true)));
            });
    }
}
