<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\Ingredient;
use App\Models\LoyaltyRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.1 — assembles the device config bundle: everything a terminal
 * caches to render the POS and ring a sale offline, tenant-scoped to the
 * device's company (catalogue) and branch (structure + stock).
 *
 * Two modes:
 *  - FULL  ($since === null): every active row.
 *  - DELTA ($since set): only rows changed (updated_at > since) since the
 *    device last synced, plus a `deleted` map of ids soft-deleted after
 *    `since` so the device can purge its local cache.
 *
 * Money is emitted as integer BAISAS (1 OMR = 1000 baisas) — the device
 * does no float math. Quantities (recipe/stock) stay decimal numbers.
 */
class BuildDeviceConfigAction
{
    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Device $device, ?Carbon $since = null): array
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        // All floors ever attached to this branch (incl. soft-deleted) so
        // tables — which scope by floor — resolve even under a just-removed
        // floor; needed for an accurate delete list.
        $branchFloorIds = Floor::withTrashed()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->all();

        // ---- Branch + dine-in structure ----
        $branch = $this->changed(Branch::query()->where('company_id', $companyId)->whereKey($branchId), $since)->first();

        $floors = $this->changed(
            Floor::query()->where('company_id', $companyId)->where('branch_id', $branchId)->orderBy('display_order'),
            $since
        )->get();

        $tables = $this->changed(
            Table::query()->where('company_id', $companyId)->whereIn('floor_id', $branchFloorIds ?: [0])->orderBy('display_order'),
            $since
        )->get();

        // ---- Catalogue (company-scoped) ----
        $categories = $this->changed(
            ProductCategory::query()->where('company_id', $companyId)->orderBy('display_order'),
            $since
        )->get();

        $products = $this->changed(
            Product::query()->where('company_id', $companyId)->orderBy('display_order'),
            $since
        )->get();
        $productIds = $products->pluck('id')->all();

        $recipesByProduct = DB::table('pos_product_recipes')
            ->whereIn('product_id', $productIds ?: [0])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        $groupIdsByProduct = DB::table('pos_addon_group_products')
            ->whereIn('product_id', $productIds ?: [0])
            ->orderBy('display_order')
            ->get()
            ->groupBy('product_id');

        $addonGroups = $this->changed(
            AddOnGroup::query()->where('company_id', $companyId)->orderBy('display_order'),
            $since
        )->get();
        $groupIds = $addonGroups->pluck('id')->all();

        $addonsByGroup = AddOn::query()
            ->where('company_id', $companyId)
            ->whereIn('add_on_group_id', $groupIds ?: [0])
            ->orderBy('display_order')
            ->get()
            ->groupBy('add_on_group_id');

        $ingredients = $this->changed(
            Ingredient::query()->where('company_id', $companyId),
            $since
        )->get();

        // ---- Branch stock ----
        $branchStock = $this->changed(
            BranchStock::query()->where('branch_id', $branchId),
            $since
        )->get();

        // ---- Discounts + targets ----
        $discounts = $this->changed(
            Discount::query()->where('company_id', $companyId),
            $since
        )->get();
        $targetsByDiscount = DB::table('pos_discount_targets')
            ->whereIn('discount_id', $discounts->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('discount_id');

        // ---- Loyalty rules ----
        $loyaltyRules = $this->changed(
            LoyaltyRule::query()->where('company_id', $companyId),
            $since
        )->get();

        // ---- Customer cache slice ----
        $customers = $this->changed(
            Customer::query()->where('company_id', $companyId),
            $since
        )->get();

        $data = [
            'branch' => $branch ? $this->mapBranch($branch) : null,
            'floors' => $floors->map(fn (Floor $f): array => $this->mapFloor($f))->all(),
            'tables' => $tables->map(fn (Table $t): array => $this->mapTable($t))->all(),
            'categories' => $categories->map(fn (ProductCategory $c): array => $this->mapCategory($c))->all(),
            'products' => $products->map(fn (Product $p): array => $this->mapProduct(
                $p,
                $recipesByProduct->get($p->id),
                $groupIdsByProduct->get($p->id),
            ))->all(),
            'addon_groups' => $addonGroups->map(fn (AddOnGroup $g): array => $this->mapAddOnGroup(
                $g,
                $addonsByGroup->get($g->id),
            ))->all(),
            'ingredients' => $ingredients->map(fn (Ingredient $i): array => $this->mapIngredient($i))->all(),
            'branch_stock' => $branchStock->map(fn (BranchStock $s): array => $this->mapBranchStock($s))->all(),
            'discounts' => $discounts->map(fn (Discount $d): array => $this->mapDiscount(
                $d,
                $targetsByDiscount->get($d->id),
            ))->all(),
            'loyalty_rules' => $loyaltyRules->map(fn (LoyaltyRule $r): array => $this->mapLoyaltyRule($r))->all(),
            'customers' => $customers->map(fn (Customer $c): array => $this->mapCustomer($c))->all(),
            'deleted' => $this->deletedMap($companyId, $branchId, $branchFloorIds, $since),
        ];

        return [
            'data' => $data,
            'meta' => [
                'mode' => $since ? 'delta' : 'full',
                'since' => $since?->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
                'money_unit' => 'baisas',
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ],
        ];
    }

    /**
     * Restrict a query to rows changed after $since (delta), or leave it
     * untouched (full). The SoftDeletes default scope already excludes
     * trashed rows from this "changed" set — they surface in deletedMap().
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function changed(Builder $query, ?Carbon $since): Builder
    {
        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query;
    }

    /**
     * Ids soft-deleted after $since, per entity, so the device purges them.
     * Empty in full mode.
     *
     * @param  array<int>  $branchFloorIds
     * @return array<string, array<int>>
     */
    private function deletedMap(int $companyId, int $branchId, array $branchFloorIds, ?Carbon $since): array
    {
        $empty = [
            'floors' => [], 'tables' => [], 'categories' => [], 'products' => [],
            'addon_groups' => [], 'addons' => [], 'ingredients' => [], 'discounts' => [],
            'loyalty_rules' => [], 'customers' => [],
        ];

        if ($since === null) {
            return $empty;
        }

        return [
            'floors' => $this->trashedIds(Floor::query()->where('company_id', $companyId)->where('branch_id', $branchId), $since),
            'tables' => $this->trashedIds(Table::query()->where('company_id', $companyId)->whereIn('floor_id', $branchFloorIds ?: [0]), $since),
            'categories' => $this->trashedIds(ProductCategory::query()->where('company_id', $companyId), $since),
            'products' => $this->trashedIds(Product::query()->where('company_id', $companyId), $since),
            'addon_groups' => $this->trashedIds(AddOnGroup::query()->where('company_id', $companyId), $since),
            'addons' => $this->trashedIds(AddOn::query()->where('company_id', $companyId), $since),
            'ingredients' => $this->trashedIds(Ingredient::query()->where('company_id', $companyId), $since),
            'discounts' => $this->trashedIds(Discount::query()->where('company_id', $companyId), $since),
            'loyalty_rules' => $this->trashedIds(LoyaltyRule::query()->where('company_id', $companyId), $since),
            'customers' => $this->trashedIds(Customer::query()->where('company_id', $companyId), $since),
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<int>
     */
    private function trashedIds(Builder $query, Carbon $since): array
    {
        return $query->withTrashed()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '>', $since)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Convert a decimal(…,3) OMR string/number to integer baisas.
     */
    private function baisas(int|float|string|null $value): ?int
    {
        return $value === null ? null : (int) round(((float) $value) * 1000);
    }

    private function num(int|float|string|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBranch(Branch $b): array
    {
        return [
            'id' => (int) $b->id,
            'uuid' => $b->uuid,
            'name' => $b->name,
            'name_ar' => $b->name_ar,
            'code' => $b->code,
            'manager_name' => $b->manager_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'address' => $b->address,
            'latitude' => $this->num($b->latitude),
            'longitude' => $this->num($b->longitude),
            'geofence_radius_m' => (int) $b->geofence_radius_m,
            'default_order_type' => $b->default_order_type,
            'opening_hours' => $b->opening_hours_json,
            'settings' => $b->settings,
            'status' => $b->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFloor(Floor $f): array
    {
        return [
            'id' => (int) $f->id,
            'uuid' => $f->uuid,
            'branch_id' => (int) $f->branch_id,
            'name' => $f->name,
            'name_ar' => $f->name_ar,
            'display_order' => (int) $f->display_order,
            'status' => $f->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTable(Table $t): array
    {
        return [
            'id' => (int) $t->id,
            'uuid' => $t->uuid,
            'floor_id' => (int) $t->floor_id,
            'label' => $t->label,
            'seats' => (int) $t->seats,
            'min_party' => $t->min_party !== null ? (int) $t->min_party : null,
            'max_party' => $t->max_party !== null ? (int) $t->max_party : null,
            'shape' => $t->shape,
            'notes' => $t->notes,
            'qr_token' => $t->qr_token,
            'display_order' => (int) $t->display_order,
            'position_x' => $t->position_x !== null ? (int) $t->position_x : null,
            'position_y' => $t->position_y !== null ? (int) $t->position_y : null,
            'width' => $t->width !== null ? (int) $t->width : null,
            'height' => $t->height !== null ? (int) $t->height : null,
            'status' => $t->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCategory(ProductCategory $c): array
    {
        return [
            'id' => (int) $c->id,
            'uuid' => $c->uuid,
            'name' => $c->name,
            'name_ar' => $c->name_ar,
            'description' => $c->description,
            'image_url' => $c->image_url,
            'display_order' => (int) $c->display_order,
            'status' => $c->status,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $recipeRows
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $groupRows
     * @return array<string, mixed>
     */
    private function mapProduct(Product $p, $recipeRows, $groupRows): array
    {
        return [
            'id' => (int) $p->id,
            'uuid' => $p->uuid,
            'category_id' => $p->category_id !== null ? (int) $p->category_id : null,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'name_ar' => $p->name_ar,
            'description' => $p->description,
            'image_url' => $p->image_url,
            'base_price_baisas' => $this->baisas($p->base_price),
            'delivery_price_baisas' => $this->baisas($p->delivery_price),
            'cost_price_baisas' => $this->baisas($p->cost_price),
            'tax_rate_percent' => $this->num($p->tax_rate),
            'display_order' => (int) $p->display_order,
            'status' => $p->status,
            'addon_group_ids' => $groupRows
                ? $groupRows->map(fn ($r): int => (int) $r->add_on_group_id)->values()->all()
                : [],
            'recipe' => $recipeRows
                ? $recipeRows->map(fn ($r): array => [
                    'ingredient_id' => (int) $r->ingredient_id,
                    'quantity' => (float) $r->quantity,
                    'unit' => $r->unit_at_set,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, AddOn>|null  $addons
     * @return array<string, mixed>
     */
    private function mapAddOnGroup(AddOnGroup $g, $addons): array
    {
        return [
            'id' => (int) $g->id,
            'uuid' => $g->uuid,
            'name' => $g->name,
            'name_ar' => $g->name_ar,
            'selection_mode' => $g->selection_mode,
            'is_global' => (bool) $g->is_global,
            'display_order' => (int) $g->display_order,
            'status' => $g->status,
            'addons' => $addons
                ? $addons->map(fn (AddOn $a): array => $this->mapAddOn($a))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAddOn(AddOn $a): array
    {
        return [
            'id' => (int) $a->id,
            'uuid' => $a->uuid,
            'add_on_group_id' => (int) $a->add_on_group_id,
            'name' => $a->name,
            'name_ar' => $a->name_ar,
            'price_delta_baisas' => $this->baisas($a->price_delta),
            'ingredient_id' => $a->ingredient_id !== null ? (int) $a->ingredient_id : null,
            'ingredient_qty' => $this->num($a->ingredient_qty),
            'ingredient_unit' => $a->ingredient_unit,
            'display_order' => (int) $a->display_order,
            'status' => $a->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapIngredient(Ingredient $i): array
    {
        return [
            'id' => (int) $i->id,
            'uuid' => $i->uuid,
            'name' => $i->name,
            'name_ar' => $i->name_ar,
            'unit' => $i->unit,
            'default_unit_cost_baisas' => $this->baisas($i->default_unit_cost),
            'min_stock_threshold' => $this->num($i->min_stock_threshold),
            'status' => $i->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBranchStock(BranchStock $s): array
    {
        return [
            'ingredient_id' => (int) $s->ingredient_id,
            'quantity' => (float) $s->quantity,
            'last_movement_at' => $s->last_movement_at?->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $targetRows
     * @return array<string, mixed>
     */
    private function mapDiscount(Discount $d, $targetRows): array
    {
        return [
            'id' => (int) $d->id,
            'uuid' => $d->uuid,
            'name' => $d->name,
            'scope' => $d->scope,
            'amount_type' => $d->amount_type,
            'amount_baisas' => $d->amount_type === 'fixed' ? $this->baisas($d->amount) : null,
            'percent' => $d->amount_type === 'percent' ? $this->num($d->amount) : null,
            'validity_start' => $d->validity_start?->toIso8601String(),
            'validity_end' => $d->validity_end?->toIso8601String(),
            'dayofweek_mask' => $d->dayofweek_mask !== null ? (int) $d->dayofweek_mask : null,
            'time_start' => $d->time_start,
            'time_end' => $d->time_end,
            'branch_scope_json' => $d->branch_scope_json,
            'stackable' => (bool) $d->stackable,
            'requires_manager_approval' => (bool) $d->requires_manager_approval,
            'status' => $d->status,
            'targets' => $targetRows
                ? $targetRows->map(fn ($r): array => [
                    'target_type' => $r->target_type,
                    'target_id' => (int) $r->target_id,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLoyaltyRule(LoyaltyRule $r): array
    {
        return [
            'id' => (int) $r->id,
            'uuid' => $r->uuid,
            'name' => $r->name,
            'type' => $r->type,
            'config' => $r->config_json,
            'validity_start' => $r->validity_start?->toIso8601String(),
            'validity_end' => $r->validity_end?->toIso8601String(),
            'status' => $r->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCustomer(Customer $c): array
    {
        return [
            'id' => (int) $c->id,
            'uuid' => $c->uuid,
            'name' => $c->name,
            'phone' => $c->phone,
            'wallet_balance_baisas' => $this->baisas($c->wallet_balance),
        ];
    }
}
