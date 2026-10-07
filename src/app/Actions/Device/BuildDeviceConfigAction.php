<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Qr\DineInRoundMode;
use App\Actions\Qr\QrScanGeofenceMode;
use App\Actions\Qr\QrTableCardEnabled;
use App\Actions\Tables\TableSessionsMode;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\CompReason;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\Device;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\Ingredient;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\MarketingSlider;
use App\Models\MarketingSliderItem;
use App\Models\Offer;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StaffMessage;
use App\Models\StaffMessageRead;
use App\Models\Table;
use App\Models\Tax;
use App\Models\VoidReason;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Catalogue\ComboLines;
use App\Support\Catalogue\CookingTime;
use App\Support\Catalogue\SaleDates;
use App\Support\OrderNumbering;
use App\Support\Pricing\CompanyTaxPolicy;
use App\Support\Recipes\OrderTypes;
use App\Support\Recipes\RecipeCopy;
use App\Support\Staff\PositionPermissions;
use App\Support\Staff\ShiftEndReminder;
use App\Support\Staff\StaffBranches;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    public function __construct(
        private readonly DineInRoundMode $dineInRoundMode,
        private readonly TableSessionsMode $tableSessionsMode,
        private readonly QrTableCardEnabled $tableCardEnabled,
        private readonly QrScanGeofenceMode $scanGeofenceMode,
        private readonly PositionPermissions $permissions,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Device $device, ?Carbon $since = null): array
    {
        // Fix order A-1 (M1) — ONE moment for the whole build: every read that
        // depends on the clock (the sale-date checks, sliders, announcements)
        // and meta.generated_at, which devices send back as the next cursor.
        // A cursor stamped at the start is at-least-once: anything edited
        // while the build ran comes again next time; nothing is skipped.
        $at = now()->toImmutable();
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
        // LAUNCH-P4 — only ACTIVE categories offered at THIS branch (their
        // branch list, M1); any other one changed since the cursor rides
        // deleted.categories, so devices need not filter (they still may).
        $categories = $this->changed(
            ProductCategory::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('display_order'),
            $since
        )->get()->filter(fn (ProductCategory $c): bool => $this->categoryAtBranch($c, $branchId))->values();

        // Per-branch availability + unit stock for THIS branch.
        // pos_branch_product also carries the per-branch unit stock attached
        // to each product below.
        $branchProductByProduct = DB::table('pos_branch_product')
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('product_id');

        // LAUNCH-P4 — the server sends only ACTIVE products sold at THIS
        // branch by their branch scope ({@see BranchCatalogue}: 'all' = every
        // branch except one switched off here; 'selected' = only branches
        // switched on; stock rows never restrict). Anything else that changed
        // since the cursor rides deleted.products. Channel flags
        // (sold_in_store / sold_on_delivery / listed) and the branch's
        // sold-out switch ride on each product for the device to apply per
        // order type — a product sold only inside combos still reaches the
        // device for its combo builder.
        $productsQuery = $this->sellableProducts($companyId, $branchId, $at)->orderBy('display_order');
        $soldOut = BranchCatalogue::soldOutAt($branchId);
        $reemitAll = $since !== null && $this->addonGroupsChangedSince($companyId, $since);

        // Delta change-detection for products must ALSO fire when only the
        // per-branch shelf moved — NOT just on pos_products.updated_at. A
        // cooked product's sellable cap is its branch shelf count
        // (branch_stock_qty, emitted below from pos_branch_product.stock_qty),
        // and a kitchen batch finish (FinishProductionAction) or a sale
        // decrement (ConsumeInventoryAction) bumps pos_branch_product WITHOUT
        // touching pos_products.updated_at. The plain updated_at gate would
        // therefore omit the product and the device would keep a stale cap —
        // e.g. a 2nd production batch of 5 never lifts the POS cap from 5 to
        // 10 even though the kitchen shelf reads 10. So for a delta, OR in an
        // EXISTS on THIS branch's pivot row changed after the cursor (the
        // pivot carries timestamps); the full sync ($since === null) keeps
        // emitting every product with its live shelf and is left untouched.
        // LAUNCH-P4 — also when this branch's sold-out switch for it changed,
        // when a combo's slots or options changed, and (M2) every product
        // when an add-on group changed: a global group joins every product.
        if ($since !== null && ! $reemitAll) {
            $this->changedProducts($productsQuery, $branchId, $since, $at);
        }

        $products = $productsQuery->get();
        $productIds = $products->pluck('id')->all();

        // LAUNCH combo add-on — combos and meals as lists of lines (per ONE
        // combo / meal). The items a line may serve are resolved for THIS
        // branch: products sold here today (read here, a delta may not carry
        // them). Every pull re-sends every combo (changedProducts) and the
        // whole meal set, so a product joining a choice category reaches the
        // devices with its combos and meals.
        $comboIds = $products->filter(static fn (Product $p): bool => $p->isCombo())->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $meals = ComboLines::meals($companyId, SaleDates::day($at));
        $comboLines = ComboLines::load($comboIds, $meals->keys()->map(static fn ($id): int => (int) $id)->all());
        $lineRefs = ComboLines::references($comboLines['combos']->flatten(1)->merge($comboLines['meals']->flatten(1)));
        $mealCategoryIds = $meals->flatMap(static fn (object $meal): array => $meal->categories)->unique()->values()->all();
        $lineItems = $this->sellableProducts($companyId, $branchId, $at)
            ->where('product_type', Product::TYPE_STANDARD)
            ->where(static fn (Builder $q) => $q->whereIn('pos_products.id', $lineRefs['products'] ?: [0])
                ->orWhereIn('pos_products.category_id', array_values(array_unique(array_merge($lineRefs['categories'], $mealCategoryIds))) ?: [0]))
            ->orderBy('display_order')->orderBy('id')->get()->keyBy('id');
        // M2 — "Apply to every product" groups join every product's list.
        $globalGroupIds = AddOnGroup::query()->where('company_id', $companyId)->where('is_global', true)
            ->where('status', 'active')->orderBy('display_order')->orderBy('id')->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        $recipeRowsByProduct = DB::table('pos_product_recipes')
            ->whereIn('product_id', $productIds ?: [0])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        // LAUNCH-P3 P3-4 — the device sees every recipe as raw ingredient
        // lines: prep items explode into what they are made of (one batched
        // read of the prep graph for the whole bundle). The recipe shape and
        // units are unchanged, so older apps read it as before.
        $recipeCopy = new RecipeCopy($companyId);
        $exploder = $recipeCopy->exploder();
        $exploder->preload($recipeRowsByProduct->flatten(1)->pluck('ingredient_id')->all());
        // Fix order PK-A1 (M1) — the device uses a recipe only for kitchen
        // production: one line per ingredient, never the sum of an item's
        // lines with disjoint ticks (unchanged while every line is 15).
        $recipesByProduct = $recipeRowsByProduct->map(fn (Collection $rows): array => $exploder->explode(
            collect(OrderTypes::widestLinePerItem($rows))->map(static fn (object $row): array => [
                'ingredient_id' => (int) $row->ingredient_id,
                'quantity' => $row->quantity,
                'unit' => $row->unit_at_set,
            ])->all(),
        ));

        // ---- Phase D2 — LOW STOCK badge inputs. A unit-mode product is low
        // when its branch unit stock is at/below its own low_stock_threshold;
        // an ingredient-mode product is low when ANY recipe ingredient's
        // branch balance is below that ingredient's min_stock_threshold
        // (the same semantics as the merchant dashboard's low-stock count).
        // Queried directly — NOT from the delta-filtered $ingredients /
        // $branchStock collections — so delta responses compute correctly.
        // LAUNCH-P3: over the exploded raw lines (a prep item has no stock).
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

        // PD3b — per-option stock-usage lines, bulk-loaded for every addon
        // in this slice (the device gates option availability on them).
        $consumptionByAddon = DB::table('pos_addon_consumptions')
            ->whereIn('add_on_id', $addonsByGroup->flatten()->pluck('id')->all() ?: [0])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy('add_on_id');
        // LAUNCH-P3 — option lines naming a prep item explode too (one batched read).
        $exploder->preload($consumptionByAddon->flatten(1)->pluck('ingredient_id')->filter()->all());

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
        // LAUNCH-P3 — a prep item has no stock of its own, so it never reaches
        // the device's ingredient list (restock picker, day-end count). One
        // that became a prep item since the cursor is purged via
        // deleted.ingredients. Read as an attribute: no P3 column, no prep.
        $prepIngredientIds = $ingredients
            ->filter(static fn (Ingredient $i): bool => (bool) ($i->is_prep ?? false))
            ->map(static fn (Ingredient $i): int => (int) $i->id)
            ->values()
            ->all();
        $ingredients = $ingredients
            ->reject(static fn (Ingredient $i): bool => (bool) ($i->is_prep ?? false))
            ->values();

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

        // ---- Phase 3 — marketing sliders (platform advertising loop on the
        // customer screen). The ONE slice that is NOT company-scoped: the
        // platform sells ad time across merchants, so a slider reaches THIS
        // device when it is `active`, inside its validity window, and either
        // targets this device, targets this device's branch (device_id null),
        // or has no targets at all (= everywhere). The full matching set rides
        // on EVERY pull (full + delta, like `settings`) and the device replaces
        // its slider set wholesale — so item/target edits propagate without
        // per-row delta bookkeeping or a deleted.sliders purge list. Small by
        // nature (a handful of loops, a few items each).
        // "Live + in this device's loop" lives on the model as ONE pair of
        // scopes, shared with the slider.display impression gate — the two
        // must never drift again (see MarketingSlider::scopeLiveAt).
        $now = $at;
        $sliders = MarketingSlider::query()
            ->liveAt($now)
            ->servedToDevice($device)
            ->with(['items' => fn ($q) => $q->orderBy('sort_order'), 'items.contentAsset'])
            ->orderBy('id')
            ->get();

        // ---- P-G6 — staff announcements (portal → device). The device
        // shows company-wide + this-branch messages to whoever is on the
        // till, and staff-targeted ones only to that logged-in staff
        // member — so the slice carries every message addressed to this
        // BRANCH's audience: company-wide, this branch, or any staff OF
        // this branch. Windowed to the last 30 days (announcements are
        // ephemeral; the portal keeps the full history). A read receipt
        // touch()es the message, so the updated read-set resurfaces in
        // deltas; retractions surface in deleted.staff_messages.
        // LAUNCH-P5 — staff of this branch = home branch or pos_staff_branches.
        $branchStaffIds = StaffBranches::worksAt(PosStaff::query()->where('company_id', $companyId), $branchId)
            ->pluck('id')
            ->all();
        $staffMessages = $this->changed(
            StaffMessage::query()
                ->where('company_id', $companyId)
                ->where('created_at', '>=', $at->subDays(30))
                ->where(function ($q) use ($branchId, $branchStaffIds): void {
                    $q->where('target_type', StaffMessage::TARGET_COMPANY)
                        ->orWhere(function ($w) use ($branchId): void {
                            $w->where('target_type', StaffMessage::TARGET_BRANCH)
                                ->where('target_branch_id', $branchId);
                        })
                        ->orWhere(function ($w) use ($branchStaffIds): void {
                            $w->where('target_type', StaffMessage::TARGET_STAFF)
                                ->whereIn('target_staff_id', $branchStaffIds ?: [0]);
                        });
                }),
            $since
        )->orderByDesc('created_at')->limit(100)->get();
        $readsByMessage = StaffMessageRead::query()
            ->whereIn('staff_message_id', $staffMessages->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('staff_message_id');

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

        // ---- Company taxes. LAUNCH-P4: the EFFECTIVE set — the active rows
        // when the merchant is VAT-registered, none otherwise — on every pull
        // (full + delta, a handful of rows), so a registration change or a
        // switched-off tax reaches the device at once; every other tax id of
        // the company rides deleted.taxes on a delta. `company.tax` says how
        // the device prices and prints them (inclusive or on top). ----
        $taxPolicy = CompanyTaxPolicy::for($companyId);
        $taxes = $taxPolicy->effectiveTaxes();

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

        // LAUNCH-P5 — the resolved tick list (every position × action, the
        // defaults filling anything missing). The three old lists below are
        // derived from it, so old app builds and the server agree.
        $positionPermissions = $this->permissions->forCompany($companyId);

        $data = [
            // Company POS policy the device enforces (v2 #14). Always emitted
            // (full + delta) so a policy change reaches the device promptly — it
            // is a tiny scalar block, not a delta-tracked collection.
            'settings' => [
                // Old builds only: opens the Order History cancel screen (not
                // mapped into the tick list; order.void_paid decides now).
                'order_cancel_positions' => $this->positionListSetting($companyId, 'order_cancel_positions'),
                // P-F1 — staff positions whose PIN authorizes sensitive POS
                // actions. LAUNCH-P5: the positions holding approvals.give —
                // the same set /device/auth/verify-manager-pin accepts.
                'manager_approval_positions' => PositionPermissions::holders($positionPermissions, 'approvals.give'),
                // P-F6 — staff positions allowed to open the device's branch
                // Reports dashboard. LAUNCH-P5: the positions holding reports.view.
                'reports_positions' => PositionPermissions::holders($positionPermissions, 'reports.view'),
                // P-G1 — staff positions allowed to open the Kitchen screen.
                // LAUNCH-P5: the positions holding kitchen.screen (the kitchen
                // position always does).
                'kitchen_positions' => PositionPermissions::holders($positionPermissions, 'kitchen.screen'),
                // P-F8 — merchant-defined order numbering policy. Always the
                // full normalised five-key shape ({enabled:false, prefix:'',
                // pad:4, scope:'branch', daily_reset:false} when unset). The
                // device requests the actual number from
                // POST /device/orders/next-number at payment time and uses
                // prefix/pad to format its OFFLINE local-counter fallback.
                'order_numbering' => OrderNumbering::forCompany($companyId),
                // QR-003 T0 — effective value for this device's branch; the
                // server reads it again on every round, never trusting this hint.
                'dine_in_round_mode' => $this->dineInRoundMode->forBranch($companyId, $branchId),
                'table_sessions_mode' => $this->tableSessionsMode->forBranch($companyId, $branchId),
                'qr_table_card_enabled' => $this->tableCardEnabled->forBranch($companyId, $branchId) ? 'on' : 'off',
                'qr_scan_geofence_mode' => $this->scanGeofenceMode->forBranch($companyId, $branchId),
                // LAUNCH-P5 — { position: { actions: { key: bool }, discount_max_percent } }
                // for all 5 positions and 19 actions (defaults fill any gap).
                'position_permissions' => $positionPermissions,
                // LAUNCH-P5 — the branch's shift-end reminder time, "HH:MM"
                // (Asia/Muscat), or null when off.
                'shift_end_reminder_at' => ShiftEndReminder::forBranch($companyId, $branchId),
            ],
            // LAUNCH-P1 2a — this device's own settings (full + delta, a tiny
            // scalar block like 'settings'). location_mode 'any' turns the
            // device's branch location lock off; 'branch' keeps it on.
            // LAUNCH-P5 add-on — the EFFECTIVE mode: 'any' while the branch's
            // "Location check" is off; every delta carries it, so a toggle
            // reaches the branch's devices on their next refresh.
            'device' => [
                'uuid' => $device->uuid,
                'device_type' => $device->device_type,
                'location_mode' => $device->effectiveLocationMode(),
            ],
            // LAUNCH-P4 — the merchant's VAT policy (full + delta, tiny):
            // {vat_registered, prices_include_vat, vat_number}. Not
            // registered = `taxes` is empty: compute and print no VAT.
            'company' => [
                'tax' => $taxPolicy->deviceBlock(),
            ],
            'branch' => $branch ? $this->mapBranch($branch) : null,
            'floors' => $floors->map(fn (Floor $f): array => $this->mapFloor($f))->all(),
            'tables' => $tables->map(fn (Table $t): array => $this->mapTable($t))->all(),
            'categories' => $categories->map(fn (ProductCategory $c): array => $this->mapCategory(
                $c,
                $groupIdsByCategory->get($c->id),
            ))->all(),
            'products' => $products->map(fn (Product $p): array => array_replace($this->mapProduct(
                $p,
                $recipesByProduct->get($p->id),
                $groupIdsByProduct->get($p->id),
                $branchProductByProduct->get($p->id),
                $deliveryPricesByProduct->get($p->id),
                $minThresholdByIngredient,
                $branchBalanceByIngredient,
            ), $this->launchP4ProductFields($p, $soldOut, $globalGroupIds, $groupIdsByProduct->get($p->id), $comboLines['combos']->get((int) $p->id), $lineItems)))->all(),
            // LAUNCH combo add-on — every active meal on sale today, on EVERY
            // pull (full and delta): the device replaces its meal set wholesale.
            'meals' => $meals->map(fn (object $meal): array => $this->mapMeal($meal, $comboLines['meals']->get($meal->id, collect()), $lineItems, $meals))->values()->all(),
            'delivery_providers' => $deliveryProviders->map(fn ($p): array => $this->mapDeliveryProvider($p))->all(),
            'addon_groups' => $addonGroups->map(fn (AddOnGroup $g): array => $this->mapAddOnGroup(
                $g,
                $addonsByGroup->get($g->id),
                $consumptionByAddon,
                $recipeCopy,
            ))->all(),
            'ingredients' => $ingredients->map(fn (Ingredient $i): array => $this->mapIngredient($i))->all(),
            'branch_stock' => $branchStock->map(fn (BranchStock $s): array => $this->mapBranchStock($s))->all(),
            'discounts' => $discounts->map(fn (Discount $d): array => $this->mapDiscount(
                $d,
                $targetsByDiscount->get($d->id),
            ))->all(),
            'offers' => $offers->map(fn (Offer $o): array => $this->mapOffer($o))->all(),
            'sliders' => $sliders->map(fn (MarketingSlider $s): array => $this->mapSlider($s))->all(),
            'staff_messages' => $staffMessages->map(fn (StaffMessage $m): array => $this->mapStaffMessage(
                $m,
                $readsByMessage->get($m->id),
            ))->all(),
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
            'deleted' => $this->deletedMap($companyId, $branchId, $branchFloorIds, $since, $at, $prepIngredientIds)
                + ['taxes' => $since === null ? [] : $this->nonEffectiveTaxIds($companyId, $taxes)],
        ];

        return [
            'data' => $data,
            'meta' => [
                'mode' => $since ? 'delta' : 'full',
                'since' => $since?->toIso8601String(),
                'generated_at' => $at->toIso8601String(),
                'money_unit' => 'baisas',
                'company_id' => $companyId,
                'branch_id' => $branchId,
                ...app(ResolveDeviceSoftPos::class)->clientContract($device, request()->header('X-Mithqal-SoftPos-Capable') === '1'),
                // Phase C3 (§9.3/§11.5) — where the device should dial its
                // Reverb WebSocket. Null when broadcasting isn't configured.
                'websocket' => $this->websocketMeta(),
                // Marketing #46 — server-driven audience-measurement gate.
                // True ONLY when the merchant consented (admin-set company
                // setting); the device must keep its camera off otherwise.
                // Supersedes the device-local settings toggle.
                'audience_measurement' => $this->audienceMeasurement($companyId),
            ],
        ];
    }

    /**
     * The per-company audience-measurement consent (pos_company_settings key
     * `audience_measurement`). Anything but an explicit true is OFF — the
     * camera-based viewer counting defaults closed.
     */
    private function audienceMeasurement(int $companyId): bool
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'audience_measurement')
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return $value === true;
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
     * pos_company_settings (v2 #14 order_cancel_positions; since LAUNCH-P5
     * the other three lists are derived from the tick list). Falls back to
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
     * @param  Builder<Model>  $query
     * @return Builder<Model>
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
     * @param  list<int>  $prepIngredientIds  changed ingredients that are prep items (LAUNCH-P3)
     * @return array<string, array<int>>
     */
    private function deletedMap(int $companyId, int $branchId, array $branchFloorIds, ?Carbon $since, CarbonInterface $at, array $prepIngredientIds = []): array
    {
        $empty = [
            'floors' => [], 'tables' => [], 'categories' => [], 'products' => [],
            'addon_groups' => [], 'addons' => [], 'ingredients' => [], 'discounts' => [],
            'offers' => [], 'staff_messages' => [], 'loyalty_rules' => [], 'customers' => [],
            'delivery_providers' => [], 'expense_categories' => [],
            'void_reasons' => [], 'comp_reasons' => [],
        ];

        if ($since === null) {
            return $empty;
        }

        return [
            'floors' => $this->trashedIds(Floor::query()->where('company_id', $companyId)->where('branch_id', $branchId), $since),
            'tables' => $this->trashedIds(Table::query()->where('company_id', $companyId)->whereIn('floor_id', $branchFloorIds ?: [0]), $since),
            // LAUNCH-P4 — plus categories changed since the cursor that are no
            // longer offered here (switched off, or this branch left their list).
            'categories' => array_values(array_unique(array_merge(
                $this->trashedIds(ProductCategory::query()->where('company_id', $companyId), $since),
                ProductCategory::query()->where('company_id', $companyId)->where('updated_at', '>', $since)->get()
                    ->reject(fn (ProductCategory $c): bool => $c->status === 'active' && $this->categoryAtBranch($c, $branchId))
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ))),
            // P-G2 — soft-deleted products PLUS products flipped internal
            // since the cursor: the changed-rows list filters internal items
            // out, so without this purge a tile flipped internal after it
            // reached a device would linger on its menu forever.
            //
            // ALSO — a product DISABLED at THIS branch since the cursor
            // (pos_branch_product.is_available flipped true→false via the
            // merchant's SyncProductBranchesAction). That is a pivot-only
            // write — NOT a soft-delete and NOT an is_internal flip — so
            // neither source above catches it; the availability filter on the
            // products query then omits it from the changed set, and the
            // product payload carries no per-branch availability field for the
            // device to filter on locally (unlike categories). Without this
            // purge a tile already cached on a delta device would keep showing
            // and selling forever until the next full re-sync (login/activate).
            // Symmetric to the per-branch shelf-qty delta re-emit above: an
            // EXISTS on THIS branch's pivot row changed after the cursor and
            // now hidden. Scoped to the device's company via the Product query.
            //
            // LAUNCH-P4 — generalised: every product changed since the cursor
            // (its row, this branch's row or sold-out switch) that is not
            // sellable here now — inactive, internal, or outside its branch
            // scope — is purged, so devices no longer filter on status.
            'products' => array_values(array_unique(array_merge(
                $this->trashedIds(Product::query()->where('company_id', $companyId), $since),
                array_values(array_diff(
                    tap(Product::query()->where('company_id', $companyId), fn (Builder $q) => $this->changedProducts($q, $branchId, $since, $at))
                        ->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $this->sellableProducts($companyId, $branchId, $at)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                )),
            ))),
            'addon_groups' => $this->trashedIds(AddOnGroup::query()->where('company_id', $companyId), $since),
            'addons' => $this->trashedIds(AddOn::query()->where('company_id', $companyId), $since),
            // LAUNCH-P3 — plus ingredients that became prep items since the
            // cursor: they leave the device's ingredient list.
            'ingredients' => array_values(array_unique(array_merge(
                $this->trashedIds(Ingredient::query()->where('company_id', $companyId), $since),
                $prepIngredientIds,
            ))),
            'discounts' => $this->trashedIds(Discount::query()->where('company_id', $companyId), $since),
            'offers' => $this->trashedIds(Offer::query()->where('company_id', $companyId), $since),
            // P-G6 — portal-retracted announcements purge from devices.
            'staff_messages' => $this->trashedIds(StaffMessage::query()->where('company_id', $companyId), $since),
            'loyalty_rules' => $this->trashedIds(LoyaltyRule::query()->where('company_id', $companyId), $since),
            'customers' => $this->trashedIds(Customer::query()->where('company_id', $companyId), $since),
            // LAUNCH-P4 H10 — plus providers switched off since the cursor.
            'delivery_providers' => DB::table('pos_delivery_providers')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->where(fn ($d) => $d->whereNotNull('deleted_at')->where('deleted_at', '>', $since))
                    ->orWhere(fn ($i) => $i->where('is_active', false)->where('updated_at', '>', $since)))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'expense_categories' => $this->trashedIds(ExpenseCategory::query()->where('company_id', $companyId), $since),
            // LAUNCH-P4 H10 — deleted or switched-off void / comp reasons leave
            // the device at once (a paid sale using one is flagged, never refused).
            'void_reasons' => $this->retiredReasonIds(VoidReason::query()->where('company_id', $companyId), $since),
            'comp_reasons' => $this->retiredReasonIds(CompReason::query()->where('company_id', $companyId), $since),
        ];
    }

    /**
     * LAUNCH-P4 H10 — reason ids soft-deleted, or switched off, after $since.
     *
     * @param  Builder<Model>  $query
     * @return list<int>
     */
    private function retiredReasonIds(Builder $query, Carbon $since): array
    {
        return $query->withTrashed()
            ->where(fn (Builder $q) => $q->where(fn (Builder $d) => $d->whereNotNull('deleted_at')->where('deleted_at', '>', $since))
                ->orWhere(fn (Builder $i) => $i->where('is_active', false)->where('updated_at', '>', $since)))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * LAUNCH-P4 (H10) — every tax id of the company that does not apply now:
     * soft-deleted, switched off, or all of them when the merchant is not
     * VAT-registered. The set is tiny, so it rides every delta and the
     * device's tax list always equals the effective one.
     *
     * @param  Collection<int, Tax>  $effective
     * @return list<int>
     */
    private function nonEffectiveTaxIds(int $companyId, Collection $effective): array
    {
        $keep = $effective->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return Tax::withTrashed()->where('company_id', $companyId)
            ->whereNotIn('id', $keep ?: [0])->orderBy('id')->pluck('id')
            ->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param  Builder<Model>  $query
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
            // LAUNCH-P5 add-on — informational; devices apply device.location_mode.
            'location_check_enabled' => $b->locationCheckEnabled(),
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
     * @param  Collection<int, \stdClass>|null  $groupRows
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
     * @param  list<array{ingredient_id: int, quantity: string, unit: string|null}>|null  $recipeRows  exploded raw lines (LAUNCH-P3)
     * @param  Collection<int, \stdClass>|null  $groupRows
     * @param  Collection<int|string, mixed>  $minThresholdByIngredient
     * @param  Collection<int|string, mixed>  $branchBalanceByIngredient
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
            // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
            // The device Finish dialog prefills the batch expiry from it.
            'shelf_life_days' => $p->shelf_life_days !== null ? (int) $p->shelf_life_days : null,
            'addon_group_ids' => $groupRows
                ? $groupRows->map(fn ($r): int => (int) $r->add_on_group_id)->values()->all()
                : [],
            // LAUNCH-P3 — raw ingredient lines (prep items exploded), per ONE
            // unit, in the ingredient's base unit: the same shape as before.
            'recipe' => $recipeRows
                ? array_map(static fn (array $line): array => [
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity' => (float) $line['quantity'],
                    'unit' => $line['unit'],
                ], $recipeRows)
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
            // LAUNCH-P4 — `listed` false hides the product on that provider;
            // a blank price resolves here to the product's delivery price,
            // else its base price, so price_baisas is never null.
            'delivery_prices' => $deliveryPriceRows
                ? $deliveryPriceRows->map(fn ($r): array => [
                    'provider_id' => (int) $r->delivery_provider_id,
                    'price_baisas' => $this->baisas($r->price ?? $p->delivery_price ?? $p->base_price),
                    'listed' => (bool) ($r->listed ?? true),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * LAUNCH-P4 — the product fields the data contract adds:
     *
     *   product_type      'standard' | 'combo'
     *   sold_in_store     offered for in-store order types (quick, dine_in,
     *                     to_go, car) — the device filters its grid on it
     *   sold_on_delivery  offered on delivery orders (with delivery_prices
     *                     [].listed per provider)
     *   sold_out          this branch switched it off by hand (never stock)
     *   description_ar    the Arabic description
     *   addon_group_ids   own + category-free bindings plus every active
     *                     "Apply to every product" group (M2); none for a
     *                     combo (its items carry the add-ons)
     *   combo             LAUNCH combo add-on, for a combo: {lines: [...]}
     *                     ({@see linePayload()})
     *
     * @param  array<int, true>  $soldOut
     * @param  list<int>  $globalGroupIds
     * @param  Collection<int, \stdClass>|null  $groupRows
     * @param  Collection<int, object>|null  $lines  the combo's lines
     * @param  Collection<int|string, Product>  $lineItems  products a line may serve here today
     * @return array<string, mixed>
     */
    private function launchP4ProductFields(Product $p, array $soldOut, array $globalGroupIds, $groupRows, ?Collection $lines, Collection $lineItems): array
    {
        $combo = $p->isCombo();
        $optionMinutes = $combo ? collect($this->lineProducts($lines ?? collect(), $lineItems))
            ->map(static fn (Product $item): ?int => CookingTime::of($item)) : collect();
        $own = $groupRows ? $groupRows->map(fn ($r): int => (int) $r->add_on_group_id)->values()->all() : [];
        $fields = [
            'product_type' => $combo ? Product::TYPE_COMBO : Product::TYPE_STANDARD,
            'sold_in_store' => (bool) ($p->sold_in_store ?? true),
            'sold_on_delivery' => (bool) ($p->sold_on_delivery ?? true),
            'sold_out' => isset($soldOut[(int) $p->id]),
            'description_ar' => $p->description_ar,
            'addon_group_ids' => $combo ? [] : array_values(array_unique(array_merge($own, $globalGroupIds))),
            // LAUNCH review add-on (additive keys, tester call 17): the
            // limited-time dates ('YYYY-MM-DD' | null, inclusive, Asia/Muscat;
            // new names — available_from / available_until stay the daily
            // hours) and the cooking time in minutes (int | null).
            'on_sale_from' => SaleDates::format($p->on_sale_from ?? null),
            'on_sale_until' => SaleDates::format($p->on_sale_until ?? null),
            'cooking_minutes' => $combo ? CookingTime::comboFigure($p, $optionMinutes) : CookingTime::of($p),
        ];
        if ($combo) {
            $fields['combo'] = ['lines' => collect($lines ?? [])->map(fn (object $line): array => $this->linePayload($line, $lineItems))->values()->all()];
        }

        return $fields;
    }

    /**
     * LAUNCH combo add-on — one line of a combo or meal (the same keys for
     * both kinds; the other kind's are null / []):
     *
     *   {id, kind: 'fixed' | 'choice', sort_order,
     *    product_id, quantity,                       fixed: the item × quantity
     *    upgrades: [{product_id, upgrade_price_baisas, sort_order}],
     *    name, name_ar, category_id, pick_count,     choice: the question
     *    items: [{product_id, extra_price_baisas}]}  choice: what it offers
     *
     * Upgrades and choice items are the products sold at this branch today
     * (active, not internal, standard, branch scope, sale dates); the device
     * also drops a sold_out one. A fixed item missing from the bundle's
     * products (or sold out) makes the combo / meal unavailable, as does a
     * choice line with no item left.
     *
     * @param  Collection<int|string, Product>  $lineItems
     * @return array<string, mixed>
     */
    private function linePayload(object $line, Collection $lineItems): array
    {
        $fixed = $line->kind === ComboLines::FIXED;

        return [
            'id' => $line->id,
            'kind' => $line->kind,
            'sort_order' => $line->sort_order,
            'product_id' => $fixed ? $line->product_id : null,
            'quantity' => $fixed ? $line->quantity : null,
            'upgrades' => $fixed ? $line->upgrades->filter(static fn (object $u): bool => $lineItems->has((int) $u->product_id))
                ->map(fn (object $u): array => [
                    'product_id' => (int) $u->product_id,
                    'upgrade_price_baisas' => (int) $this->baisas($u->upgrade_price),
                    'sort_order' => (int) $u->sort_order,
                ])->values()->all() : [],
            'name' => $fixed ? null : $line->name,
            'name_ar' => $fixed ? null : $line->name_ar,
            'category_id' => $fixed ? null : $line->category_id,
            'pick_count' => $fixed ? null : $line->pick_count,
            'items' => $fixed ? [] : $lineItems->filter(static fn (Product $item): bool => ComboLines::choiceOffers($line, $item))
                ->map(static fn (Product $item): array => [
                    'product_id' => (int) $item->id,
                    'extra_price_baisas' => ComboLines::choiceExtraBaisas($line, (int) $item->id),
                ])->values()->all(),
        ];
    }

    /**
     * The products the lines can serve here today (fixed items, upgrades,
     * choice items).
     *
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, Product>  $lineItems
     * @return list<Product>
     */
    private function lineProducts(Collection $lines, Collection $lineItems): array
    {
        $out = [];
        foreach ($lines as $line) {
            foreach ($lineItems as $item) {
                if ((int) $item->id === $line->product_id || $line->upgrades->has((int) $item->id) || ComboLines::choiceOffers($line, $item)) {
                    $out[(int) $item->id] = $item;
                }
            }
        }

        return array_values($out);
    }

    /**
     * LAUNCH combo add-on — one meal ("Make it a meal?"):
     *
     *   {id, uuid, name, name_ar, meal_price_baisas, sort_order,
     *    on_sale_from, on_sale_until ('YYYY-MM-DD' | null),
     *    categories: [category ids], excluded: [unticked product ids],
     *    mains: [product ids sold here today that get the offer: the ones
     *            whose meal this is ({@see ComboLines::mealFor()} over the
     *            active meals on sale today, as the pricer and the pricing
     *            check use; combo fix order 2, C-16)],
     *    lines: [...] ({@see linePayload()})}
     *
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, Product>  $lineItems
     * @param  Collection<int, object>  $meals  the active meals on sale today
     * @return array<string, mixed>
     */
    private function mapMeal(object $meal, Collection $lines, Collection $lineItems, Collection $meals): array
    {
        return [
            'id' => (int) $meal->id,
            'uuid' => $meal->uuid,
            'name' => $meal->name,
            'name_ar' => $meal->name_ar,
            'meal_price_baisas' => (int) $this->baisas($meal->meal_price),
            'sort_order' => (int) $meal->sort_order,
            'on_sale_from' => SaleDates::format($meal->on_sale_from),
            'on_sale_until' => SaleDates::format($meal->on_sale_until),
            'categories' => $meal->categories,
            'excluded' => $meal->excluded,
            'mains' => $lineItems->filter(static fn (Product $item): bool => ComboLines::mealFor($meals, $item)?->id === $meal->id)
                ->keys()->map(static fn ($id): int => (int) $id)->values()->all(),
            'lines' => $lines->map(fn (object $line): array => $this->linePayload($line, $lineItems))->values()->all(),
        ];
    }

    /**
     * LAUNCH-P4 — the products this branch's devices may sell: active, not
     * internal, sold here by branch scope ({@see BranchCatalogue}).
     *
     * @return Builder<Product>
     */
    private function sellableProducts(int $companyId, int $branchId, CarbonInterface $at): Builder
    {
        return SaleDates::onSale(BranchCatalogue::soldAt(
            Product::query()->where('company_id', $companyId)
                // P-G2 — internal items (cups/lids) never reach the POS menu.
                ->where('is_internal', false)
                ->where('status', 'active'),
            $branchId,
            // LAUNCH review add-on — and only while on sale (its limited-time
            // dates cover the merchant's today): old builds do not know the
            // dates, so an out-of-range product is not sent at all.
        ), SaleDates::day($at));
    }

    /**
     * Delta change-detection for products: the product row, THIS branch's
     * shelf / availability row, THIS branch's sold-out switch changed after
     * the cursor, or (LAUNCH combo add-on) it is a combo (always re-sent).
     *
     * @param  Builder<Product>  $query
     */
    private function changedProducts(Builder $query, int $branchId, Carbon $since, CarbonInterface $at): void
    {
        $query->where(function (Builder $q) use ($since, $branchId, $at): void {
            $q->where('pos_products.updated_at', '>', $since)
                ->orWhereExists(function ($sub) use ($since, $branchId): void {
                    $sub->selectRaw('1')->from('pos_branch_product')
                        ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                        ->where('pos_branch_product.branch_id', $branchId)
                        ->where('pos_branch_product.updated_at', '>', $since);
                })
                ->orWhereExists(function ($sub) use ($since, $branchId): void {
                    $sub->selectRaw('1')->from('pos_product_sold_out')
                        ->whereColumn('pos_product_sold_out.product_id', 'pos_products.id')
                        ->where('pos_product_sold_out.branch_id', $branchId)
                        ->where('pos_product_sold_out.updated_at', '>', $since);
                })
                // LAUNCH combo add-on — every combo, on every pull: its
                // choice items follow its categories live (a product added
                // to a category later joins), so it is always re-sent.
                ->orWhere('pos_products.product_type', Product::TYPE_COMBO);
            // LAUNCH review add-on — a limited-time date boundary crossed since
            // the cursor moves no row: a product whose first day has come
            // arrives, one whose last day has passed leaves via deleted.products.
            // Fix order A-1 (M1) — the cursor's day is read a margin earlier
            // ({@see SaleDates::CURSOR_MARGIN_MINUTES}), so a cursor stamped just
            // after midnight by a build that read the catalogue just before it
            // still sees the boundary (re-sending or re-purging is idempotent).
            SaleDates::orCrossedSince($q, SaleDates::cursorDay($since), SaleDates::day($at));
        });
    }

    /** M2 — any add-on group edited or removed after the cursor (a global group joins every product). */
    private function addonGroupsChangedSince(int $companyId, Carbon $since): bool
    {
        return AddOnGroup::withTrashed()->where('company_id', $companyId)
            ->where(fn (Builder $q) => $q->where('updated_at', '>', $since)->orWhere('deleted_at', '>', $since))
            ->exists();
    }

    /** LAUNCH-P4 M1 — a category is offered at a branch when it has no branch list or lists it. */
    private function categoryAtBranch(ProductCategory $category, int $branchId): bool
    {
        $branches = $category->branch_availability_json;

        return $branches === null || in_array($branchId, array_map('intval', (array) $branches), true);
    }

    /**
     * Phase D2 — LOW STOCK badge per stock mode. LAUNCH-P2 P2-7 (sell, but
     * warn): nothing is ever sold out on stock numbers, so the badge — a
     * non-blocking hint — also covers what used to be "sold out":
     *
     *   unit       → this branch's unit stock is at or below zero, or at/below
     *                the product's own low_stock_threshold.
     *   ingredient → ANY recipe ingredient whose branch balance cannot cover
     *                one portion (recipe availability at or below zero), or
     *                that sits below its min_stock_threshold (blueprint
     *                §5.5.3; mirrors the merchant dashboard low-stock count).
     *   untracked  → never.
     *
     * LAUNCH-P3: the recipe lines are the exploded raw ones, so a dish made
     * with a sauce warns when an ingredient of the sauce runs short.
     *
     * @param  list<array{ingredient_id: int, quantity: string, unit: string|null}>|null  $recipeRows
     * @param  Collection<int|string, mixed>|null  $minThresholdByIngredient
     * @param  Collection<int|string, mixed>|null  $branchBalanceByIngredient
     */
    private function isLowStock(Product $p, $recipeRows, $branchProduct, $minThresholdByIngredient, $branchBalanceByIngredient): bool
    {
        // P-G1: cooked products sell from the same branch shelf count as
        // unit products, so the LOW STOCK badge follows the same rule.
        if ($p->stock_mode === 'unit' || $p->stock_mode === 'cooked') {
            if ($branchProduct === null || $branchProduct->stock_qty === null) {
                return false;
            }
            $qty = (float) $branchProduct->stock_qty;

            return $qty <= 0 || ($p->low_stock_threshold !== null && $qty <= (float) $p->low_stock_threshold);
        }

        if ($p->stock_mode === 'ingredient' && $recipeRows !== null) {
            foreach ($recipeRows as $line) {
                $balance = (float) ($branchBalanceByIngredient?->get($line['ingredient_id']) ?? 0);
                // Not enough for one portion: the recipe can make nothing.
                if ((float) $line['quantity'] > 0 && $balance < (float) $line['quantity']) {
                    return true;
                }
                $threshold = $minThresholdByIngredient?->get($line['ingredient_id']);
                if ($threshold !== null && $balance < (float) $threshold) {
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
     * @param  Collection<int|string, mixed>|null  $consumptionByAddon
     * @return array<string, mixed>
     */
    private function mapAddOnGroup(AddOnGroup $g, $addons, $consumptionByAddon = null, ?RecipeCopy $recipeCopy = null): array
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
            // LAUNCH review add-on (tester call 1) — 'extras' | 'remove' |
            // 'instructions'. Old builds drop the key and show every group as
            // an ordinary optional add-on group.
            'kind' => (string) ($g->kind ?? 'extras'),
            'addons' => $addons
                ? $addons->map(fn (AddOn $a): array => $this->mapAddOn($a, $consumptionByAddon?->get($a->id), $recipeCopy))->values()->all()
                : [],
        ];
    }

    /**
     * @param  Collection<int, mixed>|null  $consumptionLines
     * @return array<string, mixed>
     */
    private function mapAddOn(AddOn $a, $consumptionLines = null, ?RecipeCopy $recipeCopy = null): array
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
            // P-G3 — the real product behind this option: the device greys
            // the add-on when that product is sold out at the branch.
            'linked_product_id' => $a->linked_product_id !== null ? (int) $a->linked_product_id : null,
            // LAUNCH review add-on — a Remove option's recipe ingredient (the
            // server leaves it out of the line's recipe copy).
            'removes_ingredient_id' => $a->removes_ingredient_id !== null ? (int) $a->removes_ingredient_id : null,
            'ingredient_id' => $a->ingredient_id !== null ? (int) $a->ingredient_id : null,
            'ingredient_qty' => $this->num($a->ingredient_qty),
            'ingredient_unit' => $a->ingredient_unit,
            // PD3b — the option's stock-usage lines (ingredient XOR product,
            // direction add|remove, qty per ONE parent line unit, ingredient
            // qty in the ingredient's BASE unit). The device gates option
            // availability on the 'add' lines it can see. LAUNCH-P3: prep
            // ingredient lines are exploded into raw ones (same shape).
            // LAUNCH packaging add-on — no "Used for" masks: devices never
            // take stock from these lines, so they merge exactly as before.
            'consumption' => $consumptionLines === null ? [] : array_map(static fn (array $c): array => [
                'type' => $c['type'],
                'ingredient_id' => $c['type'] === 'ingredient' ? $c['ingredient_id'] : null,
                'product_id' => $c['type'] === 'product' ? $c['product_id'] : null,
                'direction' => $c['direction'],
                'qty' => $c['qty'],
                'unit' => $c['type'] === 'ingredient' ? $c['unit'] : null,
            ], ($recipeCopy ?? new RecipeCopy((int) $a->company_id))->consumptionLines($consumptionLines, byOrderType: false)),
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
     * @param  Collection<int, \stdClass>|null  $targetRows
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
     * Phase 3 — a marketing slider in the device shape: the ordered loop + each
     * slide's media. Items with a missing or not-yet-approved asset are dropped
     * (an asset can be un-approved after it was added). `duration_seconds` is the
     * on-screen time the device honours for BOTH images and (capped) videos,
     * falling back to the slider's loop interval. URLs are absolute + device-
     * reachable (rebuilt from the marketing public base).
     */
    private function mapSlider(MarketingSlider $s): array
    {
        $items = $s->items
            ->filter(fn (MarketingSliderItem $i): bool => $i->contentAsset !== null
                && in_array($i->contentAsset->status, ['approved', 'live'], true)
                && $i->contentAsset->public_url !== null)
            ->values()
            ->map(fn (MarketingSliderItem $i): array => [
                'id' => (int) $i->id,
                'content_asset_id' => (int) $i->content_asset_id,
                'advertiser_id' => $i->advertiser_id !== null ? (int) $i->advertiser_id : null,
                'sort_order' => (int) $i->sort_order,
                'duration_seconds' => (int) ($i->duration_seconds ?: $s->loop_interval_seconds),
                'type' => $i->contentAsset->type,
                'url' => $i->contentAsset->public_url,
                'thumbnail_url' => $i->contentAsset->thumbnail_public_url,
            ])
            ->all();

        return [
            'id' => (int) $s->id,
            'uuid' => $s->uuid,
            'name' => $s->name,
            'loop_interval_seconds' => (int) $s->loop_interval_seconds,
            'items' => $items,
        ];
    }

    /**
     * P-G6 — a staff announcement in the device shape. read_staff_ids is
     * the receipt set: the device computes "unread for the logged-in
     * staff" from it (cross-till reads heal because a new receipt
     * touch()es the message and the delta resurfaces it).
     *
     * @param  Collection<int, StaffMessageRead>|null  $reads
     * @return array<string, mixed>
     */
    private function mapStaffMessage(StaffMessage $m, $reads = null): array
    {
        return [
            'id' => (int) $m->id,
            'target_type' => $m->target_type,
            'target_staff_id' => $m->target_staff_id !== null ? (int) $m->target_staff_id : null,
            'title' => $m->title,
            'body' => $m->body,
            'created_by_name' => $m->created_by_name,
            'created_at' => $m->created_at?->toIso8601String(),
            'read_staff_ids' => collect($reads ?? [])
                ->map(fn ($r): int => (int) $r->staff_id)
                ->values()
                ->all(),
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
     * @param  Collection<int, LoyaltyAccount>|null  $accounts
     * @param  Collection<int, CustomerVehiclePlate>|null  $plates
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
