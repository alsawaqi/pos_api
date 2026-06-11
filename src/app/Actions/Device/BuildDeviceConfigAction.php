<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\Device;
use App\Models\CompReason;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\Ingredient;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Table;
use App\Models\Tax;
use App\Models\VoidReason;
use App\Support\OrderNumbering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.1 — assembles the device config bundle: everything a terminal
 * caches to render the POS and ring a sale offline, tenant-scoped to the
 * device's company (catalogue) and branch (structure, per-branch product
 * availability + stock).
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

        // Per-branch availability + unit stock for THIS branch. A product is
        // shown to the device when it's assigned-and-available at this branch,
        // OR not assigned to any branch at all (default: available everywhere,
        // which keeps pre-feature catalogues working). pos_branch_product also
        // carries the per-branch unit stock attached to each product below.
        $branchProductByProduct = DB::table('pos_branch_product')
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('product_id');

        $products = $this->changed(
            Product::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $q) use ($branchId): void {
                    $q->whereExists(function ($sub) use ($branchId): void {
                        $sub->selectRaw('1')->from('pos_branch_product')
                            ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                            ->where('pos_branch_product.branch_id', $branchId)
                            ->where('pos_branch_product.is_available', true);
                    })->orWhereNotExists(function ($sub): void {
                        $sub->selectRaw('1')->from('pos_branch_product')
                            ->whereColumn('pos_branch_product.product_id', 'pos_products.id');
                    });
                })
                ->orderBy('display_order'),
            $since
        )->get();
        $productIds = $products->pluck('id')->all();

        $recipesByProduct = DB::table('pos_product_recipes')
            ->whereIn('product_id', $productIds ?: [0])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        // ---- Phase D2 — LOW STOCK badge inputs. A unit-mode product is low
        // when its branch unit stock is at/below its own low_stock_threshold;
        // an ingredient-mode product is low when ANY recipe ingredient's
        // branch balance is below that ingredient's min_stock_threshold
        // (the same semantics as the merchant dashboard's low-stock count).
        // Queried directly — NOT from the delta-filtered $ingredients /
        // $branchStock collections — so delta responses compute correctly.
        $recipeIngredientIds = $recipesByProduct
            ->flatten(1)
            ->pluck('ingredient_id')
            ->unique()
            ->values()
            ->all();
        $minThresholdByIngredient = DB::table('pos_ingredients')
            ->whereIn('id', $recipeIngredientIds ?: [0])
            ->whereNull('deleted_at')
            ->whereNotNull('min_stock_threshold')
            ->pluck('min_stock_threshold', 'id');
        $branchBalanceByIngredient = DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->whereIn('ingredient_id', $recipeIngredientIds ?: [0])
            ->pluck('quantity', 'ingredient_id');

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

        // ---- Delivery providers + per-product price overrides (§6.3). The POS
        // shows the provider picker on a delivery order; each product's price
        // then resolves override → delivery_price → base_price on the device. ----
        $deliveryProviders = DB::table('pos_delivery_providers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('sort_order')
            ->get();

        $deliveryPricesByProduct = DB::table('pos_product_delivery_prices')
            ->whereIn('product_id', $productIds ?: [0])
            ->get()
            ->groupBy('product_id');

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

        // ---- P-F9 — offers / promotions. Same delta tracking as
        // discounts (changed by updated_at; soft-deleted ids surface in
        // deleted.offers). The DEVICE evaluates them; config rides verbatim.
        $offers = $this->changed(
            Offer::query()->where('company_id', $companyId),
            $since
        )->get();

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
        // Each cached customer's CURRENT loyalty balances (points/stamps per
        // rule) so the device can show + redeem them OFFLINE. One bulk query.
        $loyaltyAccountsByCustomer = LoyaltyAccount::query()
            ->whereIn('customer_id', $customers->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('customer_id');
        // P-F2 — each cached customer's vehicle plates so the device can
        // resolve "plate → customer" OFFLINE (drive-thru). Same one-bulk-
        // query grouping as the loyalty accounts above — never per-customer.
        $platesByCustomer = CustomerVehiclePlate::query()
            ->whereIn('customer_id', $customers->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('customer_id');

        // ---- Company taxes (active set; the POS adds each, as its own line,
        // on top of the order total — exclusive). ----
        $taxes = $this->changed(
            Tax::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order'),
            $since
        )->get();

        // ---- Expense categories (active set; the POS expense screen renders
        // these + expense.log validates the submitted key against them). ----
        $expenseCategories = $this->changed(
            ExpenseCategory::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order'),
            $since
        )->get();

        // ---- Phase B — void + comp reason code lists (active sets; the POS
        // cancel dialog and comp flow render these; order.void / order.create
        // resolve the picked reason server-side). ----
        $voidReasons = $this->changed(
            VoidReason::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order'),
            $since
        )->get();
        $compReasons = $this->changed(
            CompReason::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order'),
            $since
        )->get();

        // ---- Phase B — category-level add-on group bindings: the device
        // unions a product's own group ids with its category's, so a group
        // bound to "Drinks" applies to every drink without per-product pivots.
        $groupIdsByCategory = DB::table('pos_addon_group_categories')
            ->whereIn('category_id', $categories->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('category_id');

        $data = [
            // Company POS policy the device enforces (v2 #14). Always emitted
            // (full + delta) so a policy change reaches the device promptly — it
            // is a tiny scalar block, not a delta-tracked collection.
            'settings' => [
                'order_cancel_positions' => $this->positionListSetting($companyId, 'order_cancel_positions'),
                // P-F1 — staff positions whose PIN authorizes sensitive POS
                // actions (comps, cancellations, gifts) as the fingerprint
                // fallback. Verified server-side by /device/auth/verify-manager-pin.
                'manager_approval_positions' => $this->positionListSetting($companyId, 'manager_approval_positions'),
                // P-F6 — staff positions allowed to open the device's branch
                // Reports dashboard (GET /device/reports/branch). The DEVICE
                // gates its Reports screen on this list.
                'reports_positions' => $this->positionListSetting($companyId, 'reports_positions'),
                // P-F8 — merchant-defined order numbering policy. Always the
                // full normalised five-key shape ({enabled:false, prefix:'',
                // pad:4, scope:'branch', daily_reset:false} when unset). The
                // device requests the actual number from
                // POST /device/orders/next-number at payment time and uses
                // prefix/pad to format its OFFLINE local-counter fallback.
                'order_numbering' => OrderNumbering::forCompany($companyId),
            ],
            'branch' => $branch ? $this->mapBranch($branch) : null,
            'floors' => $floors->map(fn (Floor $f): array => $this->mapFloor($f))->all(),
            'tables' => $tables->map(fn (Table $t): array => $this->mapTable($t))->all(),
            'categories' => $categories->map(fn (ProductCategory $c): array => $this->mapCategory(
                $c,
                $groupIdsByCategory->get($c->id),
            ))->all(),
            'products' => $products->map(fn (Product $p): array => $this->mapProduct(
                $p,
                $recipesByProduct->get($p->id),
                $groupIdsByProduct->get($p->id),
                $branchProductByProduct->get($p->id),
                $deliveryPricesByProduct->get($p->id),
                $minThresholdByIngredient,
                $branchBalanceByIngredient,
            ))->all(),
            'delivery_providers' => $deliveryProviders->map(fn ($p): array => $this->mapDeliveryProvider($p))->all(),
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
            'offers' => $offers->map(fn (Offer $o): array => $this->mapOffer($o))->all(),
            'loyalty_rules' => $loyaltyRules->map(fn (LoyaltyRule $r): array => $this->mapLoyaltyRule($r))->all(),
            'customers' => $customers->map(fn (Customer $c): array => $this->mapCustomer(
                $c,
                $loyaltyAccountsByCustomer->get($c->id),
                $platesByCustomer->get($c->id),
            ))->all(),
            'taxes' => $taxes->map(fn (Tax $t): array => $this->mapTax($t))->all(),
            'expense_categories' => $expenseCategories->map(fn (ExpenseCategory $c): array => $this->mapExpenseCategory($c))->all(),
            'void_reasons' => $voidReasons->map(fn (VoidReason $r): array => $this->mapVoidReason($r))->all(),
            'comp_reasons' => $compReasons->map(fn (CompReason $r): array => $this->mapCompReason($r))->all(),
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
                'terminal_id' => $device->terminal_id,
                // Phase C3 (§9.3/§11.5) — where the device should dial its
                // Reverb WebSocket. Null when broadcasting isn't configured.
                'websocket' => $this->websocketMeta(),
            ],
        ];
    }

    /**
     * The device-facing Reverb endpoint (Phase C3). `host` null = "dial the
     * same host you already reach the API on" — in dev the LAN IP only the
     * device knows; in prod set REVERB_PUBLIC_HOST to the wss hostname.
     * Returns null unless the reverb broadcaster is active and has an app key,
     * so devices skip the WebSocket entirely on un-configured installs.
     *
     * @return array{app_key: string, host: string|null, port: int, scheme: string}|null
     */
    private function websocketMeta(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }
        $key = (string) config('broadcasting.connections.reverb.key', '');
        if ($key === '') {
            return null;
        }

        return [
            'app_key' => $key,
            'host' => config('broadcasting.connections.reverb.public.host'),
            'port' => (int) config('broadcasting.connections.reverb.public.port', 8080),
            'scheme' => (string) config('broadcasting.connections.reverb.public.scheme', 'http'),
        ];
    }

    /**
     * A staff-position-list policy read from the merchant-written
     * pos_company_settings (v2 #14 order_cancel_positions, P-F1
     * manager_approval_positions, P-F6 reports_positions). Falls back to
     * managers-only when the merchant hasn't set a policy — the safe
     * default matching the device's legacy "manager approval" gate.
     *
     * @return list<string>
     */
    private function positionListSetting(int $companyId, string $key): array
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->value('value');

        $positions = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($positions)) {
            $positions = [];
        }

        $positions = array_values(array_filter(
            array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $positions),
            static fn (string $p): bool => $p !== '',
        ));

        return $positions === [] ? ['manager'] : $positions;
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
            'offers' => [], 'loyalty_rules' => [], 'customers' => [],
            'delivery_providers' => [], 'expense_categories' => [],
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
            'offers' => $this->trashedIds(Offer::query()->where('company_id', $companyId), $since),
            'loyalty_rules' => $this->trashedIds(LoyaltyRule::query()->where('company_id', $companyId), $since),
            'customers' => $this->trashedIds(Customer::query()->where('company_id', $companyId), $since),
            'delivery_providers' => DB::table('pos_delivery_providers')
                ->where('company_id', $companyId)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '>', $since)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'expense_categories' => $this->trashedIds(ExpenseCategory::query()->where('company_id', $companyId), $since),
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
            // Per-branch merchant-authored receipt template (header/CR/VAT/
            // footer); null = device prints its built-in default receipt.
            'receipt_template' => $b->receipt_template,
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
    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $groupRows
     */
    private function mapCategory(ProductCategory $c, $groupRows = null): array
    {
        return [
            'id' => (int) $c->id,
            'uuid' => $c->uuid,
            'name' => $c->name,
            'name_ar' => $c->name_ar,
            'description' => $c->description,
            'image_url' => $c->image_url,
            'display_order' => (int) $c->display_order,
            // Phase D2 — §5.5.1 branch availability. null = all branches;
            // else the branch ids that may show this category. The DEVICE
            // filters its category strip — the server keeps emitting every
            // category, because one newly excluded from a branch is not
            // soft-deleted and would never reach delta devices via the
            // `deleted` purge map.
            'branch_ids' => $c->branch_availability_json !== null
                ? array_values(array_map('intval', $c->branch_availability_json))
                : null,
            'status' => $c->status,
            // Phase B — groups bound at the CATEGORY level; the device unions
            // these with each product's own addon_group_ids.
            'addon_group_ids' => $groupRows
                ? $groupRows->pluck('add_on_group_id')->map(fn ($id): int => (int) $id)->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTax(Tax $t): array
    {
        return [
            'id' => (int) $t->id,
            'uuid' => $t->uuid,
            'name' => $t->name,
            'name_ar' => $t->name_ar,
            'rate_percent' => (float) $t->rate_percent,
            'is_active' => (bool) $t->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapExpenseCategory(ExpenseCategory $c): array
    {
        return [
            'id' => (int) $c->id,
            'uuid' => $c->uuid,
            // The device submits `key` back on expense.log; name/name_ar drive
            // the UI label.
            'key' => $c->key,
            'name' => $c->name,
            'name_ar' => $c->name_ar,
            'sort_order' => (int) $c->sort_order,
        ];
    }

    /**
     * Phase B — a void reason code (order.void sends the id back).
     *
     * @return array<string, mixed>
     */
    private function mapVoidReason(VoidReason $r): array
    {
        return [
            'id' => (int) $r->id,
            'uuid' => $r->uuid,
            'code' => $r->code,
            'name' => $r->name,
            'name_ar' => $r->name_ar,
            'affects_inventory' => (bool) $r->affects_inventory,
            'requires_manager' => (bool) $r->requires_manager,
            'sort_order' => (int) $r->sort_order,
        ];
    }

    /**
     * Phase B — a comp reason (order.create comps send the id back).
     *
     * @return array<string, mixed>
     */
    private function mapCompReason(CompReason $r): array
    {
        return [
            'id' => (int) $r->id,
            'uuid' => $r->uuid,
            'code' => $r->code,
            'name' => $r->name,
            'name_ar' => $r->name_ar,
            'max_amount_baisas' => $r->max_amount !== null ? $this->baisas($r->max_amount) : null,
            'sort_order' => (int) $r->sort_order,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $recipeRows
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $groupRows
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $minThresholdByIngredient
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $branchBalanceByIngredient
     * @return array<string, mixed>
     */
    private function mapProduct(Product $p, $recipeRows, $groupRows, $branchProduct = null, $deliveryPriceRows = null, $minThresholdByIngredient = null, $branchBalanceByIngredient = null): array
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
            // Phase D2 — §5.5.3 tax-inclusive flag. INFORMATIONAL ONLY for
            // now: the device's tax engine stays exclusive (taxes added on
            // top of the subtotal) so the sync money invariant (subtotal −
            // discount − comp + tax == grand ±1 baisa) is untouched. A later
            // per-line tax-engine phase consumes this.
            'tax_inclusive' => (bool) $p->tax_inclusive,
            // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no". The
            // future customer tablet consumes it; the MAIN POS must ignore
            // it (it does not gate the staff product grid).
            'show_on_customer_tablet' => (bool) $p->show_on_customer_tablet,
            'display_order' => (int) $p->display_order,
            'status' => $p->status,
            // Phase 7 — stock mode: unit (piece-counted) | ingredient
            // (recipe-driven) | untracked. Drives device sold-out enforcement.
            'stock_mode' => $p->stock_mode,
            // G1 — menu time-window. Raw 'HH:MM:SS' strings passed through
            // verbatim (the mapDiscount time_start/time_end convention): both
            // NULL = always available; start > end wraps midnight. The DEVICE
            // evaluates the predicate against its local clock — same as the
            // discount window evaluator.
            'available_from' => $p->available_from,
            'available_until' => $p->available_until,
            // Phase D2 — LOW STOCK badge (§5.5.3 / §6.3). `low_stock` is the
            // server-computed boolean as of this sync (same staleness window
            // as branch_stock_qty); the threshold rides along for a future
            // device-side recompute after offline sales.
            'low_stock' => $this->isLowStock($p, $recipeRows, $branchProduct, $minThresholdByIngredient, $branchBalanceByIngredient),
            'low_stock_threshold' => $this->num($p->low_stock_threshold),
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
            // Per-branch unit stock for the device's branch: null = not
            // unit-tracked here (unlimited / recipe-depleted); a number = the
            // units currently allocated to this branch.
            'branch_stock_qty' => $branchProduct !== null && $branchProduct->stock_qty !== null
                ? (float) $branchProduct->stock_qty
                : null,
            // Per-delivery-provider price overrides (§6.3). The device resolves
            // a delivery line as: this map's provider price → delivery_price_baisas
            // → base_price_baisas.
            'delivery_prices' => $deliveryPriceRows
                ? $deliveryPriceRows->map(fn ($r): array => [
                    'provider_id' => (int) $r->delivery_provider_id,
                    'price_baisas' => $this->baisas($r->price),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Phase D2 — LOW STOCK badge per stock mode:
     *
     *   unit       → this branch's unit stock is at/below the product's own
     *                low_stock_threshold. Stock <= 0 returns false — that is
     *                the (stronger) SOLD OUT state, not "low".
     *   ingredient → ANY recipe ingredient with a min_stock_threshold whose
     *                branch balance sits below it (blueprint §5.5.3 "when
     *                stock of the product's primary ingredient falls below
     *                X"; mirrors the merchant dashboard low-stock count).
     *   untracked  → never.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>|null  $recipeRows
     * @param  \Illuminate\Support\Collection<int|string, mixed>|null  $minThresholdByIngredient
     * @param  \Illuminate\Support\Collection<int|string, mixed>|null  $branchBalanceByIngredient
     */
    private function isLowStock(Product $p, $recipeRows, $branchProduct, $minThresholdByIngredient, $branchBalanceByIngredient): bool
    {
        if ($p->stock_mode === 'unit') {
            if ($p->low_stock_threshold === null || $branchProduct === null || $branchProduct->stock_qty === null) {
                return false;
            }
            $qty = (float) $branchProduct->stock_qty;

            return $qty > 0 && $qty <= (float) $p->low_stock_threshold;
        }

        if ($p->stock_mode === 'ingredient' && $recipeRows !== null) {
            foreach ($recipeRows as $line) {
                $threshold = $minThresholdByIngredient?->get($line->ingredient_id);
                if ($threshold === null) {
                    continue;
                }
                $balance = (float) ($branchBalanceByIngredient?->get($line->ingredient_id) ?? 0);
                if ($balance < (float) $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDeliveryProvider(object $p): array
    {
        return [
            'id' => (int) $p->id,
            'uuid' => $p->uuid,
            'name' => $p->name,
            'color' => $p->color,
            'sort_order' => (int) $p->sort_order,
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
            // Phase B — selection constraints the customize sheet enforces
            // (NULL = unbounded; min >= 1 makes the group required).
            'min_selections' => $g->min_selections !== null ? (int) $g->min_selections : null,
            'max_selections' => $g->max_selections !== null ? (int) $g->max_selections : null,
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
            // Phase B — pre-selected in the customize sheet.
            'is_default' => (bool) ($a->is_default ?? false),
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
            // Phase A (Additions §2.3) — the piece model, so the device can
            // render day-end counts in physical pieces ("5 bottles").
            'piece_unit_label' => $i->piece_unit_label,
            'piece_unit_label_ar' => $i->piece_unit_label_ar,
            'units_per_piece' => $this->num($i->units_per_piece),
            'allow_fractional_pieces' => (bool) ($i->allow_fractional_pieces ?? true),
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
            // P-F4 — merchant control over ORDER-scope auto-application:
            // true = the device applies the rule by itself to every
            // qualifying order (the existing 6-axis predicate); false =
            // cashier picks it manually. The device IGNORES this flag for
            // product/category scopes — targeted rules already auto-apply
            // per matching cart line and stay automatic (their stored
            // value is forced true merchant-side).
            'auto_apply' => (bool) $d->auto_apply,
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
     * P-F9 — an offer / promotion in THE canonical device shape. The
     * device's pure offers engine is built against EXACTLY these keys:
     * `config` is the type-specific JSON passed through verbatim (money
     * inside it is integer baisas, written that way by the merchant
     * portal); branch_scope_json is the raw array/null. Shared axes
     * (validity / dayofweek_mask / time window / branch scope / status)
     * follow the mapDiscount conventions exactly.
     *
     * @return array<string, mixed>
     */
    private function mapOffer(Offer $o): array
    {
        return [
            'id' => (int) $o->id,
            'name' => $o->name,
            'name_ar' => $o->name_ar,
            'type' => $o->type,
            'status' => $o->status,
            // Bundle offers are ALWAYS cashier-picked (forced false
            // merchant-side); the other four types default to true.
            'auto_apply' => (bool) $o->auto_apply,
            'validity_start' => $o->validity_start?->toIso8601String(),
            'validity_end' => $o->validity_end?->toIso8601String(),
            'dayofweek_mask' => $o->dayofweek_mask !== null ? (int) $o->dayofweek_mask : null,
            'time_start' => $o->time_start,
            'time_end' => $o->time_end,
            'branch_scope_json' => $o->branch_scope_json,
            'max_per_order' => $o->max_per_order !== null ? (int) $o->max_per_order : null,
            'config' => $o->config,
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
     * @param  \Illuminate\Support\Collection<int, LoyaltyAccount>|null  $accounts
     * @param  \Illuminate\Support\Collection<int, CustomerVehiclePlate>|null  $plates
     * @return array<string, mixed>
     */
    private function mapCustomer(Customer $c, $accounts = null, $plates = null): array
    {
        return [
            'id' => (int) $c->id,
            'uuid' => $c->uuid,
            'name' => $c->name,
            'phone' => $c->phone,
            'wallet_balance_baisas' => $this->baisas($c->wallet_balance),
            // P-F2 — the customer's vehicle plates (uppercased strings) so the
            // device can resolve drive-thru plate lookups offline. Many-to-
            // many: the same plate can appear under several customers.
            'plates' => collect($plates ?? [])
                ->map(fn ($p): string => (string) $p->plate_number)
                ->values()
                ->all(),
            // CURRENT loyalty balances per rule. Volatile — refreshed on each
            // full sync; the device uses them for OFFLINE view/redeem, while the
            // server still re-checks the balance authoritatively on order.pay.
            'loyalty' => collect($accounts ?? [])->map(fn ($a): array => [
                'rule_id' => (int) $a->loyalty_rule_id,
                'points' => (int) $a->point_balance,
                'stamps' => (int) $a->stamp_count,
            ])->values()->all(),
        ];
    }
}
