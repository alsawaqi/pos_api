<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\AddOn;
use App\Models\Branch;
use App\Models\CompReason;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\Catalogue\CookingTime;
use App\Support\CustomerIdentity;
use App\Support\Money;
use App\Support\Orders\OneLineNote;
use App\Support\Recipes\RecipeCopy;
use App\Support\Recipes\RecipeInForce;
use App\Support\Staff\SaleAuthorizations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Phase 8.3 — processes an `order.create` sync event into a pos_orders row
 * plus its line items and add-ons.
 *
 * Pricing is SNAPSHOT-AUTHORITATIVE (§9.1.6): the money the device computed
 * (subtotal/discount/tax/totals, per-line prices) is trusted and frozen
 * as-is — this handler validates the invariant but does NOT re-run discount
 * evaluation. It DOES own the recipe snapshot (§9.9): each line freezes the
 * product's recipe so the pay-time stock deduction is immune to later
 * recipe edits. Wire money is integer baisas → decimal OMR via
 * {@see Money}.
 *
 * LAUNCH-P3 — the recipe copied is the one in force at the device's sale
 * time (P3-6: the event's client timestamp, clamped to now; fix order 1 M2:
 * never before the device's credential epoch or the product's creation),
 * prep items are exploded into raw ingredients (P3-4) and only a
 * made-to-order product copies a recipe (P3-7); see {@see RecipeCopy}. A
 * re-sent open order keeps the copies its unchanged lines already had
 * ({@see keptLineCopies()}); its new or changed lines copy no earlier than
 * the order's last accepted write ({@see resendMoment()}).
 */
class CreateOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly GeofenceGuard $geofence,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $result = $this->writeOrder($event, $device, Order::STATUS_OPEN, enforceGeofence: true);
        $order = (array) ($event->payload_json['order'] ?? null);

        try {
            $result['pricing_check'] = app('App\\Support\\Pricing\\WireValidator')->validate(
                $order,
                (int) $device->company_id,
                (int) $device->branch_id,
                (int) $event->getKey(),
            );
        } catch (\Throwable $exception) {
            // Container resolution is outside WireValidator::validate(), so it
            // gets the same fail-open guard: pricing observation can never turn
            // a valid order.create into a failed sync event.
            try {
                logger()->warning('pricing validator error', [
                    'event_id' => (int) $event->getKey(),
                    'order_uuid' => (string) ($order['uuid'] ?? ''),
                    'company_id' => (int) $device->company_id,
                    'branch_id' => (int) $device->branch_id,
                    'source' => (string) ($order['source'] ?? ''),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            } catch (\Throwable) {
                // Logging is best-effort; the canonical error ACK still wins.
            }
            $result['pricing_check'] = ['checked' => false, 'reason' => 'error'];
        }

        return $result;
    }

    /**
     * Phase C2 — the shared write path for order.create (status=open,
     * geofenced) and order.hold (status=held, NO geofence: a hold moves no
     * money or stock and must mirror even when queued offline without a GPS
     * fix; order.pay re-checks the fence).
     *
     * UPSERT-BY-UUID: a same-uuid replaceable NON-terminal row is replaced in
     * place — a re-hold refreshes the mirror, and the finalize order.create
     * flips a held mirror to open (the device cannot know offline whether its
     * earlier order.hold reached the server). The server-owned awaiting-payment
     * state and terminal rows (paid/void/refunded) fail the event instead.
     */
    public function writeOrder(SyncEvent $event, Device $device, string $status, bool $enforceGeofence): array
    {
        $order = (array) ($event->payload_json['order'] ?? null);
        $this->validate($order, $event->event_type);
        $this->assertMoneyInvariant($order);
        if ($enforceGeofence) {
            $this->enforceGeofence($order, $device, $event);
        }

        return DB::transaction(function () use ($order, $device, $event, $status): array {
            if (isset($order['customer_id'])) {
                $customer = CustomerIdentity::lockedSurvivor((int) $device->company_id, (int) $order['customer_id']);
                if ($customer === null) {
                    throw new RuntimeException('order references a customer outside the device tenant');
                }
                $order['customer_id'] = (int) $customer->id;
            }
            $this->assertReferencesInTenant($order, $device);
            $existing = Order::query()->where('uuid', $order['uuid'])->lockForUpdate()->first();
            if ($existing !== null
                && ((int) $existing->company_id !== (int) $device->company_id
                    || (int) $existing->branch_id !== (int) $device->branch_id)) {
                // §9.11 — a uuid squatted by another tenant/branch can neither
                // be read nor overwritten; fail without leaking its contents.
                throw new RuntimeException('order not found');
            }
            if ($existing !== null && $existing->status === Order::STATUS_AWAITING_PAYMENT) {
                throw new RuntimeException(sprintf(
                    'order %s cannot be overwritten while in status %s',
                    $order['uuid'],
                    $existing->status,
                ));
            }
            if ($existing !== null
                && in_array($existing->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION, Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_COMBINED], true)) {
                // P-G7 — pending_verification blocks the upsert too: the
                // punched delivery snapshot + its consumed inventory must not
                // be silently replaced while it awaits the provider statement.
                throw new RuntimeException(sprintf('order %s already exists in terminal status %s', $order['uuid'], $existing->status));
            }

            // Shared table bills are append-only. A delayed legacy hold,
            // finalize or transfer snapshot must not purge round-owned items.
            // Check the LOCKED existing bill, never the incoming source/type.
            // Historical/back-link evidence also fails closed if a partial
            // legacy link lost the order's table_session_id. These are reads,
            // not new table/session locks after the existing order lock.
            if ($existing !== null && ($existing->table_session_id !== null
                || TableSession::query()->where('order_id', $existing->id)->exists()
                || QrOrderRound::query()->where('order_id', $existing->id)->whereNotNull('table_session_id')->exists())) {
                throw new RuntimeException('shared_table_snapshot_replacement_forbidden');
            }

            $columns = [
                'device_id' => $device->getKey(),
                // The device's GPS at order time (also used for the
                // geofence check above). Persisted so reports + support
                // see where the order was actually taken.
                'latitude' => isset($order['gps']['lat']) ? (float) $order['gps']['lat'] : null,
                'longitude' => isset($order['gps']['lng']) ? (float) $order['gps']['lng'] : null,
                'staff_id' => $order['staff_id'] ?? null,
                'customer_id' => $order['customer_id'] ?? null,
                'table_id' => $order['table_id'] ?? null,
                'order_type' => $order['order_type'],
                'status' => $status,
                'source' => $order['source'],
                'plate_number' => $order['plate_number'] ?? null,
                'subtotal' => Money::toOmr((int) $order['subtotal_baisas']),
                'discount_total' => Money::toOmr((int) $order['discount_total_baisas']),
                // Phase B — comp write-offs (0 for devices that don't comp).
                'comp_total' => Money::toOmr((int) ($order['comp_total_baisas'] ?? 0)),
                'tax_total' => Money::toOmr((int) $order['tax_total_baisas']),
                'grand_total' => Money::toOmr((int) $order['grand_total_baisas']),
                // LAUNCH-P4 — stamped from the order: true = grand already
                // contains tax (VAT-inclusive menu prices). Absent = false.
                'prices_include_tax' => self::pricesIncludeTax($order),
                'opened_at' => Carbon::parse((string) $order['opened_at']),
                'client_event_id' => $event->client_event_id,
                'note' => $order['note'] ?? null,
                // P-F8 — the printed receipt number (server-allocated via
                // /device/orders/next-number, or the device's offline local
                // fallback). OPTIONAL: orders queued offline / with
                // numbering disabled carry none. Trimmed; empty → NULL.
                'receipt_number' => $this->receiptNumber($order),
            ];

            // LAUNCH-P3 P3-6 — recipes are copied as they were at the device's
            // sale time: this event's client timestamp, clamped to now and
            // (fix order 1 M2) never before the device's credential epoch.
            // On a re-send only new or changed lines copy, never earlier than
            // the order's last accepted write (L1, {@see resendMoment()}).
            $moment = RecipeInForce::saleMoment($event->client_timestamp, null, $device->assignment_activated_at ?? $device->token_issued_at);
            if ($existing !== null) {
                $moment = $this->resendMoment($existing, $event, $moment);
            }
            $copy = new RecipeCopy((int) $device->company_id, $moment);
            $keptCopies = [];

            if ($existing !== null) {
                if ($existing->qr_session_id !== null) {
                    // QR-001 P2 — a till finalising a held QR order re-emits
                    // order.create and may omit these server/customer-owned
                    // values. Absence or null means “keep”; an explicit
                    // non-null cashier replacement still wins. source remains
                    // device-authored, while qr_session_id stays preserved by
                    // its deliberate absence from $columns.
                    foreach (['customer_id', 'plate_number', 'receipt_number'] as $column) {
                        if (! array_key_exists($column, $order) || $order[$column] === null) {
                            $columns[$column] = $existing->{$column};
                        }
                    }
                }

                // Read before the purge: unchanged lines keep these copies.
                $keptCopies = $this->keptLineCopies($existing, $order['lines']);
                $this->purgeOrderChildren($existing);
                $existing->update($columns);
                $model = $existing;
            } else {
                $model = Order::create([
                    'uuid' => $order['uuid'],
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                ] + $columns);
            }

            $itemIds = [];
            foreach ($order['lines'] as $index => $line) {
                $productId = (int) $line['product_id'];
                // withTrashed keeps the name / stock_mode / recipe snapshots
                // faithful when the product was deleted while the order sat
                // in a device's offline outbox.
                $product = Product::withTrashed()->where('company_id', $device->company_id)->find($productId);

                // LAUNCH-P3 P3-6 — a re-sent open order keeps the copy a line
                // already had unless the line changed (another product, qty
                // or add-on set); a new or changed line copies at this sale.
                // Fix order 1 L2: a kept recipe only while the product is
                // still made-to-order (P3-7 holds for kept copies too).
                $kept = $this->takeKeptLineCopy($keptCopies, $line);

                // LAUNCH review add-on — the line's Remove options (ordinary
                // add_on_ids) leave their ingredients out of its recipe copy;
                // the cooking time is the server's snapshot (devices send
                // nothing new). A kept, unchanged line keeps both copies.
                $item = OrderItem::create([
                    'order_id' => $model->id,
                    'product_id' => $productId,
                    'product_name_snapshot' => $product?->name ?? ('#'.$productId),
                    'qty' => $line['qty'],
                    'unit_price_snapshot' => Money::toOmr((int) $line['unit_price_baisas']),
                    'line_discount' => Money::toOmr((int) ($line['line_discount_baisas'] ?? 0)),
                    'line_total' => Money::toOmr((int) $line['line_total_baisas']),
                    'recipe_snapshot_json' => $kept !== null
                        ? ((string) $product?->stock_mode === 'ingredient' ? $kept['recipe'] : null)
                        : $copy->productRecipe($product, $copy->removedIngredientIds($this->addOnIdsOf($line))),
                    'component_snapshot_json' => $kept !== null ? $kept['components'] : $this->snapshotComponents($productId),
                    'status' => OrderItem::STATUS_OPEN,
                    // Fix order A-1 (H1) — one line on the kitchen ticket; cut, never refused.
                    'notes' => OneLineNote::cut($line['notes'] ?? null),
                    'cooking_minutes' => $kept !== null ? ($kept['cooking_minutes'] ?? null) : CookingTime::of($product),
                ]);
                $itemIds[$index] = (int) $item->id;

                $this->writeAddons($item, $line['addons'] ?? [], $device, $copy, $kept);

                // LAUNCH-P4 — a combo line's chosen items become its children
                // (data contract): the item, qty = combo qty × choice qty, no
                // money (the revenue sits on this line), their own recipe,
                // component and add-on copies. Never refused for an invalid
                // combo — the pricing check flags it.
                $childMinutes = [];
                foreach ($line['combo'] ?? [] as $component) {
                    $childId = (int) $component['product_id'];
                    $child = Product::withTrashed()->where('company_id', $device->company_id)->find($childId);
                    $keptChild = $kept !== null ? $this->takeKeptChildCopy($kept, $component) : null;
                    $childMinutes[] = $childCooking = $keptChild !== null ? ($keptChild['cooking_minutes'] ?? null) : CookingTime::of($child);
                    $childItem = OrderItem::create([
                        'order_id' => $model->id,
                        'parent_order_item_id' => $item->id,
                        'product_id' => $childId,
                        'product_name_snapshot' => $child?->name ?? ('#'.$childId),
                        'qty' => round((float) $line['qty'] * (float) $component['qty'], 3),
                        'unit_price_snapshot' => Money::toOmr(0),
                        'line_discount' => Money::toOmr(0),
                        'line_total' => Money::toOmr(0),
                        'recipe_snapshot_json' => $keptChild !== null
                            ? ((string) $child?->stock_mode === 'ingredient' ? $keptChild['recipe'] : null)
                            : $copy->productRecipe($child, $copy->removedIngredientIds($this->addOnIdsOf($component))),
                        'component_snapshot_json' => $keptChild !== null ? $keptChild['components'] : $this->snapshotComponents($childId),
                        'combo_slot_id' => (int) $component['slot_id'],
                        'combo_extra_price' => Money::toOmr((int) ($component['extra_price_baisas'] ?? 0)),
                        'status' => OrderItem::STATUS_OPEN,
                        'notes' => OneLineNote::cut($component['notes'] ?? null),
                        'cooking_minutes' => $childCooking,
                    ]);
                    $this->writeAddons($childItem, $component['addons'] ?? [], $device, $copy, $keptChild);
                }
                // LAUNCH review add-on — a combo parent stores its longest
                // child's cooking time (else the combo's own value).
                if ($kept === null && $product !== null && $product->isCombo()) {
                    $parentCooking = CookingTime::forLine($product, $childMinutes);
                    if ($parentCooking !== $item->cooking_minutes) {
                        $item->update(['cooking_minutes' => $parentCooking]);
                    }
                }
            }

            // LAUNCH-P5 — check the sale's gated actions (manual discounts
            // above the position's maximum, "needs manager" rules, comps,
            // gifts and any other block) and record them in pos_approvals.
            // Never refuses: a paid sale is never rejected over approvals.
            $authorizations = $event->event_type === 'order.create'
                ? app(SaleAuthorizations::class)->forCreate($event, $device, $order)
                : ['comp_approvers' => [], 'summary' => []];

            $discountCount = $this->writeDiscounts($order, $model, $device, $itemIds);
            $compCount = $this->writeComps($order, $model, $device, $itemIds, $authorizations['comp_approvers']);
            $joinedCount = $this->writeJoinedTables($order, $model);

            return [
                'order_id' => (int) $model->id,
                'order_uuid' => $model->uuid,
                'status' => $model->wasRecentlyCreated ? 'created' : 'updated',
                'order_status' => $status,
                'discounts' => $discountCount,
                'comps' => $compCount,
                'joined_tables' => $joinedCount,
            ] + ($authorizations['summary'] === [] ? [] : ['authorizations' => $authorizations['summary']]);
        });
    }

    /**
     * A line's add-on rows with their frozen stock-use copies (a kept copy
     * when the re-sent line is unchanged) — for a top-level line and, since
     * LAUNCH-P4, for each combo child.
     *
     * @param  list<array<string, mixed>>  $addons
     * @param  array<string, mixed>|null  $kept
     */
    private function writeAddons(OrderItem $item, array $addons, Device $device, RecipeCopy $copy, ?array &$kept): void
    {
        foreach ($addons as $addon) {
            $addOnId = (int) $addon['add_on_id'];
            $addOn = AddOn::withTrashed()->where('company_id', $device->company_id)->find($addOnId);
            $keptAddon = $kept !== null && ($kept['addons'][$addOnId] ?? []) !== []
                ? $this->withCurrentProductType(array_shift($kept['addons'][$addOnId]), $device->company_id)
                : null;
            // PD3b — per-option stock-usage lines, frozen at create.
            // When present they SUPERSEDE the legacy single-ingredient
            // trio for this addon (never both — no double-count).
            $stockUse = $keptAddon ?? ($addOn !== null
                ? $copy->addonStockUse($addOn) + [
                    // P-G3 — product-as-add-on freeze: id for reporting,
                    // snapshot for consumption by the product's type.
                    'linked_product_id' => $addOn->linked_product_id !== null ? (int) $addOn->linked_product_id : null,
                    'product_snapshot_json' => $this->snapshotAddonProduct($addOn, $device->company_id, $copy),
                ]
                : ['ingredient_snapshot_json' => null, 'consumption_snapshot_json' => null, 'linked_product_id' => null, 'product_snapshot_json' => null]);
            OrderItemAddon::create([
                'order_item_id' => $item->id,
                'add_on_id' => $addOnId,
                'add_on_name_snapshot' => $addOn?->name ?? ('#'.$addOnId),
                'price_delta_snapshot' => Money::toOmr((int) ($addon['price_delta_baisas'] ?? 0)),
                'ingredient_snapshot_json' => $stockUse['ingredient_snapshot_json'],
                'linked_product_id' => $stockUse['linked_product_id'],
                'product_snapshot_json' => $stockUse['product_snapshot_json'],
                'consumption_snapshot_json' => $stockUse['consumption_snapshot_json'],
            ]);
        }
    }

    /**
     * The add_on_ids of a wire line or combo choice (its `addons` rows).
     *
     * @param  array<string, mixed>  $line
     * @return list<int>
     */
    private function addOnIdsOf(array $line): array
    {
        return array_values(array_map(
            static fn ($addon): int => (int) (is_array($addon) ? ($addon['add_on_id'] ?? 0) : 0),
            is_array($line['addons'] ?? null) ? $line['addons'] : [],
        ));
    }

    /**
     * P-F8 — the optional printed receipt number, trimmed; absent/empty →
     * NULL (validation already capped it at 24 chars).
     *
     * @param  array<string, mixed>  $order
     */
    private function receiptNumber(array $order): ?string
    {
        $value = trim((string) ($order['receipt_number'] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Phase C2 — drop an upserted order's child rows before the rewrite. The
     * payload is snapshot-authoritative, so replacement (not diffing) is the
     * correct semantics for a re-held / finalized mirror.
     */
    private function purgeOrderChildren(Order $order): void
    {
        $itemIds = OrderItem::query()->where('order_id', $order->id)->pluck('id');
        if ($itemIds->isNotEmpty()) {
            OrderItemAddon::query()->whereIn('order_item_id', $itemIds)->delete();
            OrderItem::query()->whereIn('id', $itemIds)->delete();
        }
        OrderDiscount::query()->where('order_id', $order->id)->delete();
        OrderComp::query()->where('order_id', $order->id)->delete();
        // Joined-table coverage rows (v2) — cleared on every upsert so a
        // re-hold/finalize reinserts a fresh set.
        DB::table('pos_order_tables')->where('order_id', $order->id)->delete();
    }

    /**
     * Joined dine-in tables (v2) — one pos_order_tables row per EXTRA table the
     * party's single shared order covered. The primary table_id (on pos_orders)
     * is excluded so the order isn't double-listed under its own table.
     * Tenant-validated in assertReferencesInTenant; runs inside the order write
     * transaction after purgeOrderChildren cleared any prior rows.
     *
     * @param  array<string, mixed>  $order
     */
    private function writeJoinedTables(array $order, Order $model): int
    {
        $primaryId = isset($order['table_id']) ? (int) $order['table_id'] : null;
        // A joined seat is meaningless without a primary table. Never record
        // joined tables for a non-dine-in order (table_id null) even if the
        // payload (a device bug / malformed input) carries some — that would
        // surface a phantom sitting in the merchant per-table report.
        if ($primaryId === null) {
            return 0;
        }
        $joined = array_values(array_diff($this->joinedTableIds($order), [$primaryId]));
        if ($joined === []) {
            return 0;
        }

        $now = now();
        DB::table('pos_order_tables')->insert(array_map(
            static fn (int $tableId): array => [
                'order_id' => (int) $model->id,
                'table_id' => $tableId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $joined,
        ));

        return count($joined);
    }

    /**
     * Tenant-isolation guard (blueprint §9.11.2 / §9.11.4): every entity the
     * order references — products, add-ons, customer, table — MUST belong to
     * the device's own company. A reference outside the tenant fails the whole
     * event rather than silently snapshotting another company's data (which
     * would leak product names/recipes/costs and pollute another company's
     * customer loyalty). Mirrors the company-scoped resolution already used for
     * discounts ({@see writeDiscounts}). Pricing stays snapshot-authoritative;
     * this validates IDENTITY/ownership only, not money.
     *
     * @param  array<string, mixed>  $order
     */
    private function assertReferencesInTenant(array $order, Device $device): void
    {
        $companyId = $device->company_id;

        // LAUNCH-P4 — a combo's chosen items are products of the order too.
        $productIds = [];
        foreach ($order['lines'] as $line) {
            $productIds[] = (int) $line['product_id'];
            foreach ($line['combo'] ?? [] as $component) {
                $productIds[] = (int) $component['product_id'];
            }
        }
        $productIds = array_values(array_unique($productIds));
        // withTrashed: an offline-queued order may land after the merchant
        // deleted the menu item — the sale still happened and must settle
        // (the offers + staff guards below already follow this rule). The
        // guard validates OWNERSHIP, and a trashed row still proves it.
        $owned = Product::withTrashed()->where('company_id', $companyId)->whereIn('id', $productIds)->pluck('id')->all();
        $foreign = array_diff($productIds, array_map('intval', $owned));
        if ($foreign !== []) {
            throw new RuntimeException('order references product(s) outside the device tenant: '.implode(',', $foreign));
        }

        $addOnIds = [];
        foreach ($order['lines'] as $line) {
            foreach ($line['addons'] ?? [] as $addon) {
                $addOnIds[] = (int) $addon['add_on_id'];
            }
            foreach ($line['combo'] ?? [] as $component) {
                foreach ($component['addons'] ?? [] as $addon) {
                    $addOnIds[] = (int) $addon['add_on_id'];
                }
            }
        }
        $addOnIds = array_values(array_unique($addOnIds));
        if ($addOnIds !== []) {
            // Same withTrashed rationale as the product guard above.
            $ownedAddOns = AddOn::withTrashed()->where('company_id', $companyId)->whereIn('id', $addOnIds)->pluck('id')->all();
            $foreignAddOns = array_diff($addOnIds, array_map('intval', $ownedAddOns));
            if ($foreignAddOns !== []) {
                throw new RuntimeException('order references add-on(s) outside the device tenant: '.implode(',', $foreignAddOns));
            }
        }

        $customerId = isset($order['customer_id']) ? (int) $order['customer_id'] : null;
        if ($customerId !== null
            && CustomerIdentity::survivor((int) $companyId, $customerId) === null) {
            throw new RuntimeException('order references a customer outside the device tenant');
        }

        $tableId = isset($order['table_id']) ? (int) $order['table_id'] : null;
        if ($tableId !== null
            && ! Table::query()->where('company_id', $companyId)->whereHas('floor', fn ($q) => $q->where('branch_id', $device->branch_id))->whereKey($tableId)->exists()) {
            throw new RuntimeException('order references a table outside the device tenant');
        }

        // Phase 4 — attribution integrity: the order's cashier (pos_orders.staff_id
        // → every stock movement's recorded_by + the shared-shift cash/Z-report
        // attribution) must be a staff member of the device's own company.
        // withTrashed: a since-terminated cashier's offline-queued order still
        // settles.
        $staffId = isset($order['staff_id']) ? (int) $order['staff_id'] : null;
        TenantReferenceGuard::assertCashier($device, $staffId, 'order references a staff member outside the device tenant');

        // Joined dine-in tables (v2) — every EXTRA table the shared order
        // covered must also belong to the device's company (same guard as the
        // primary table above, applied to the list).
        $joinedTableIds = $this->joinedTableIds($order);
        if ($joinedTableIds !== []) {
            $ownedTables = Table::query()->where('company_id', $companyId)->whereHas('floor', fn ($q) => $q->where('branch_id', $device->branch_id))->whereIn('id', $joinedTableIds)->pluck('id')->all();
            $foreignTables = array_diff($joinedTableIds, array_map('intval', $ownedTables));
            if ($foreignTables !== []) {
                throw new RuntimeException('order references joined table(s) outside the device tenant: '.implode(',', $foreignTables));
            }
        }
    }

    /**
     * The de-duplicated, positive EXTRA joined table ids from the payload (the
     * primary table_id is NOT stripped here — that happens at write time so the
     * tenant guard validates the full set the device sent).
     *
     * @param  array<string, mixed>  $order
     * @return list<int>
     */
    private function joinedTableIds(array $order): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) ($order['joined_table_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Persist the discount-application records (§5.11.7 by-rule report data
     * path). Pricing is snapshot-authoritative — the device already evaluated
     * the rules, so this records what it applied rather than re-deriving it. A
     * discount_id that resolves to a live company rule snapshots the catalogue
     * name/type; otherwise the payload values stand (a manual / ad-hoc discount
     * carries no discount_id). A line_index ties the row to that line's item;
     * absent → an order-level discount.
     *
     * P-F9 — OFFER applications ride the same rows: an entry carrying
     * `offer_id` records which pos_offers promotion granted the amount
     * (name_snapshot = the offer's name). Unlike discount_id — which falls
     * back to the sent label when unresolvable — an offer_id that does not
     * resolve to one of the device company's offers FAILS the whole event
     * (tenant guard, §9.11). Soft-deleted offers still resolve: an offline
     * order may land after the merchant deleted the promotion.
     *
     * @param  array<string, mixed>  $order
     * @param  array<int, int>  $itemIds  line index → created order_item id
     */
    private function writeDiscounts(array $order, Order $model, Device $device, array $itemIds): int
    {
        $discounts = $order['discounts'] ?? [];
        if (! is_array($discounts) || $discounts === []) {
            return 0;
        }

        $count = 0;
        foreach ($discounts as $d) {
            $discountId = isset($d['discount_id']) ? (int) $d['discount_id'] : null;
            $rule = $discountId !== null
                ? Discount::query()->where('company_id', $device->company_id)->find($discountId)
                : null;

            // P-F9 — offer attribution. Tenant-validated HARD: a foreign /
            // unknown offer_id fails the event (unlike discount_id's soft
            // fallback). withTrashed: a soft-deleted offer is still a valid
            // historical reference for an offline-queued order.
            $offerId = isset($d['offer_id']) ? (int) $d['offer_id'] : null;
            $offer = null;
            if ($offerId !== null) {
                $offer = Offer::withTrashed()
                    ->where('company_id', $device->company_id)
                    ->find($offerId);
                if ($offer === null) {
                    throw new RuntimeException('order references an offer outside the device tenant: '.$offerId);
                }
            }

            $lineIndex = isset($d['line_index']) ? (int) $d['line_index'] : null;

            // P-F4 — optional cashier free-text reason for a manual /
            // custom discount. Trimmed + capped to the column's 160 chars
            // rather than rejected: an offline order batch must not fail
            // because a cashier typed a long note. Empty/absent → NULL.
            $reason = isset($d['reason']) ? mb_substr(trim((string) $d['reason']), 0, 160) : '';

            OrderDiscount::create([
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'order_id' => $model->id,
                'order_item_id' => $lineIndex !== null ? ($itemIds[$lineIndex] ?? null) : null,
                // Phase 4 — only a RESOLVED company rule is persisted as the FK;
                // an unresolved/foreign discount_id is dropped to null (a
                // manual/ad-hoc discount carries none, and name_snapshot below
                // still keeps the device-sent label) rather than written raw.
                'discount_id' => $rule?->id,
                'offer_id' => $offer?->id,
                // The offer's catalogue name wins for offer rows; else the
                // discount rule's; else the device-sent label.
                'name_snapshot' => $offer?->name ?? $rule?->name ?? (string) $d['name'],
                'amount_type_snapshot' => $rule?->amount_type ?? ($d['amount_type'] ?? null),
                'amount' => Money::toOmr((int) $d['amount_baisas']),
                'reason' => $reason !== '' ? $reason : null,
                'applied_at' => $model->opened_at,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Phase B — persist the comp write-offs (Additions §1.2). A MANAGER COMP
     * carries a valid company comp reason (unlike a discount it can never be
     * ad-hoc) and is capped by the reason's max_amount when set. The food was
     * made and given away, so inventory deducts normally at pay; only the
     * money is written off. line_index ties a comp to one line; absent →
     * whole-order comp.
     *
     * P-F5 — PER-ITEM GIFTS ride the same table: an entry with
     * is_gift === true is a 100% line write-off given away whole, so it
     * carries NO comp_reason_id (must be absent/null) and NO cap. Every
     * other entry still requires a tenant-valid reason. Gift rows snapshot
     * the fixed 'gift'/'Gift' labels so history reads without a master row.
     *
     * LAUNCH-P5 (B1) — approved_by_pos_staff_id is the comp's checked approver
     * ($approvers: comp index → the verified approver, or the actor whose own
     * position allowed it, or null), else the approver the device sent; the
     * old fallback to the cashier is gone (it recorded the wrong person).
     *
     * @param  array<string, mixed>  $order
     * @param  array<int, int>  $itemIds  line index → created order_item id
     * @param  array<int, ?int>  $approvers  comp index → approver staff id
     */
    private function writeComps(array $order, Order $model, Device $device, array $itemIds, array $approvers = []): int
    {
        $comps = $order['comps'] ?? [];
        if (! is_array($comps) || $comps === []) {
            if ((int) ($order['comp_total_baisas'] ?? 0) !== 0) {
                throw new RuntimeException('comp_total_baisas set without comp rows');
            }

            return 0;
        }

        $sum = 0;
        $count = 0;
        foreach ($comps as $compIndex => $c) {
            $isGift = ($c['is_gift'] ?? false) === true;
            $reasonId = isset($c['comp_reason_id']) ? (int) $c['comp_reason_id'] : null;
            $amountBaisas = (int) $c['amount_baisas'];

            if ($isGift && $reasonId !== null) {
                throw new RuntimeException('a gift comp must not carry a comp_reason_id');
            }
            if (! $isGift && $reasonId === null) {
                throw new RuntimeException('a comp entry requires a comp_reason_id unless is_gift is true');
            }

            $reason = null;
            if (! $isGift) {
                // LAUNCH-P4 H10 — a reason deleted after the device cached it
                // still resolves (withTrashed), and a comp above a cap lowered
                // since is flagged: a sale is never refused on reason metadata.
                // Only a reason of another company refuses (tenant guard).
                $reason = CompReason::withTrashed()
                    ->where('company_id', $device->company_id)
                    ->find($reasonId);
                if ($reason === null) {
                    throw new RuntimeException('order references a comp reason outside the device tenant: '.$reasonId);
                }
                if ($reason->trashed()) {
                    $device->syncIntegrityFlags[] = 'comp_reason_deleted:'.$reasonId;
                }
                if ($reason->max_amount !== null && $amountBaisas > (int) round(((float) $reason->max_amount) * 1000)) {
                    $device->syncIntegrityFlags[] = 'comp_over_cap:'.$reasonId;
                }
            }

            // Phase 4 — the manager who APPROVED this write-off
            // (approved_by_pos_staff_id) is an audit-integrity FK: a device
            // must not attribute a comp approval to an arbitrary staff number.
            // Same tenant + withTrashed guard as the order's cashier.
            $cashierId = isset($c['staff_id']) ? (int) $c['staff_id'] : null;
            TenantReferenceGuard::assertCashier($device, $cashierId, 'comp references a staff member outside the device tenant: '.$cashierId);
            $approverId = isset($c['approved_by_staff_id']) ? (int) $c['approved_by_staff_id'] : null;
            TenantReferenceGuard::assertApprover($device, $approverId, 'comp references an approver outside the device tenant: '.$approverId);

            $lineIndex = isset($c['line_index']) ? (int) $c['line_index'] : null;
            $qty = filter_var($c['qty'] ?? null, FILTER_VALIDATE_INT);
            $qty = $qty !== false
                && $qty >= 1
                && ! $isGift
                && $lineIndex !== null
                && isset($itemIds[$lineIndex], $order['lines'][$lineIndex]['qty'])
                && $qty < (float) $order['lines'][$lineIndex]['qty']
                    ? $qty
                    : null;
            OrderComp::create([
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'order_id' => $model->id,
                'order_item_id' => $lineIndex !== null ? ($itemIds[$lineIndex] ?? null) : null,
                'comp_reason_id' => $reason?->id,
                'reason_code_snapshot' => $reason->code ?? 'gift',
                'reason_name_snapshot' => $reason->name ?? 'Gift',
                'is_gift' => $isGift,
                'amount' => Money::toOmr($amountBaisas),
                'qty' => $qty,
                'approved_by_pos_staff_id' => array_key_exists($compIndex, $approvers) ? $approvers[$compIndex] : $approverId,
                'note' => $c['note'] ?? null,
                'applied_at' => $model->opened_at,
            ]);
            $sum += $amountBaisas;
            $count++;
        }

        // The cached order.comp_total must equal the row sum exactly.
        if ($sum !== (int) ($order['comp_total_baisas'] ?? 0)) {
            throw new RuntimeException('comp rows do not sum to comp_total_baisas');
        }

        return $count;
    }

    /**
     * Reject the order if the device's reported GPS (stamped at order time) is
     * outside the branch geofence. The device's location mode decides whether
     * a fence applies at all ({@see GeofenceGuard::requirement()}): an 'any'
     * device, or an order made while the device was 'any', is not fenced; a
     * 'branch' device at a branch without coordinates is refused.
     *
     * @param  array<string, mixed>  $order
     */
    private function enforceGeofence(array $order, Device $device, SyncEvent $event): void
    {
        $branch = Branch::find($device->branch_id);
        $requirement = $this->geofence->requirement($device, $branch, $event->client_timestamp);
        if ($requirement === GeofenceGuard::BRANCH_LOCATION_MISSING) {
            throw new RuntimeException('order rejected: '.GeofenceGuard::BRANCH_LOCATION_MISSING_MESSAGE);
        }
        if ($requirement !== GeofenceGuard::ENFORCE) {
            return;
        }

        $gps = $order['gps'] ?? null;
        if (! is_array($gps) || ! isset($gps['lat'], $gps['lng'])) {
            // Fail-closed: a fenced branch REQUIRES a GPS fix so a device
            // cannot bypass the fence by simply omitting its location.
            throw new RuntimeException('order rejected: a GPS fix is required at this geofenced branch');
        }

        $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function validate(array $order, string $eventType): void
    {
        $validator = Validator::make($order, [
            'uuid' => ['required', 'uuid'],
            'order_type' => ['required', 'string', 'in:'.implode(',', Order::TYPES)],
            'source' => ['required', 'string', 'in:'.implode(',', Order::SOURCES), 'not_in:'.Order::SOURCE_QR_WEB],
            'subtotal_baisas' => ['required', 'integer', 'min:0'],
            'discount_total_baisas' => ['required', 'integer', 'min:0'],
            'tax_total_baisas' => ['required', 'integer', 'min:0'],
            'grand_total_baisas' => ['required', 'integer', 'min:0'],
            // LAUNCH-P4 — VAT-inclusive pricing (absent = exclusive).
            'prices_include_tax' => ['sometimes', 'boolean'],
            'opened_at' => ['required', 'date'],
            // P-F8 — optional printed receipt number (server-allocated or
            // the device's offline fallback); column is varchar(24).
            'receipt_number' => ['nullable', 'string', 'max:24'],
            // Joined dine-in tables (v2) — the EXTRA table ids this one shared
            // order covered (a party joined across tables). Tenant-validated in
            // assertReferencesInTenant; the primary stays on table_id.
            'joined_table_ids' => ['sometimes', 'array'],
            'joined_table_ids.*' => ['integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_baisas' => ['required', 'integer', 'min:0'],
            'lines.*.line_total_baisas' => ['required', 'integer', 'min:0'],
            // LAUNCH-P4 — a combo line's choices, per ONE combo (data contract).
            'lines.*.combo' => ['sometimes', 'nullable', 'array'],
            'lines.*.combo.*.slot_id' => ['required', 'integer'],
            'lines.*.combo.*.product_id' => ['required', 'integer'],
            'lines.*.combo.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.combo.*.extra_price_baisas' => ['sometimes', 'integer', 'min:0'],
            'lines.*.combo.*.notes' => ['nullable', 'string'],
            'lines.*.combo.*.addons' => ['sometimes', 'array'],
            'lines.*.combo.*.addons.*.add_on_id' => ['required', 'integer'],
            'lines.*.combo.*.addons.*.price_delta_baisas' => ['sometimes', 'integer'],
            'discounts' => ['sometimes', 'array'],
            'discounts.*.discount_id' => ['nullable', 'integer'],
            // P-F9 — which pos_offers promotion granted this amount.
            // Tenant-validated hard in writeDiscounts (foreign → event fails).
            'discounts.*.offer_id' => ['nullable', 'integer'],
            'discounts.*.name' => ['required', 'string'],
            'discounts.*.amount_type' => ['nullable', 'string'],
            'discounts.*.amount_baisas' => ['required', 'integer', 'min:0'],
            'discounts.*.line_index' => ['nullable', 'integer', 'min:0'],
            // P-F4 — cashier's free-text reason for a manual discount. No
            // max rule here: writeDiscounts trims + caps to 160 instead of
            // failing the whole offline order over a long note.
            'discounts.*.reason' => ['nullable', 'string'],
            // Phase B — comp write-offs. A manager comp carries a valid
            // comp_reason_id (resolved tenant-scoped in writeComps) and a
            // positive amount. P-F5 — a GIFT entry (is_gift: true) instead
            // carries NO reason and bypasses any cap; the reason-or-gift
            // exclusivity is enforced in writeComps.
            'comp_total_baisas' => ['sometimes', 'integer', 'min:0'],
            'comps' => ['sometimes', 'array'],
            'comps.*.comp_reason_id' => ['nullable', 'integer'],
            'comps.*.is_gift' => ['sometimes', 'boolean'],
            'comps.*.amount_baisas' => ['required', 'integer', 'min:1'],
            'comps.*.line_index' => ['nullable', 'integer', 'min:0'],
            'comps.*.staff_id' => ['nullable', 'integer'],
            'comps.*.approved_by_staff_id' => ['nullable', 'integer'],
            'comps.*.note' => ['nullable', 'string'],
            'comps.*.qty' => ['nullable'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException(sprintf('invalid %s payload: %s', $eventType, implode('; ', $validator->errors()->all())));
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function assertMoneyInvariant(array $order): void
    {
        // Phase B — comps reduce what the customer pays alongside discounts
        // (comp_total_baisas defaults 0 for devices that never comp).
        // LAUNCH-P4 — a VAT-inclusive order's grand already contains its tax.
        $inclusive = self::pricesIncludeTax($order);
        $expected = (int) $order['subtotal_baisas']
            - (int) $order['discount_total_baisas']
            - (int) ($order['comp_total_baisas'] ?? 0)
            + ($inclusive ? 0 : (int) $order['tax_total_baisas']);
        if (abs($expected - (int) $order['grand_total_baisas']) > 1) {
            throw new RuntimeException($inclusive
                ? 'order money invariant violated: subtotal − discount − comp != grand_total (prices include tax)'
                : 'order money invariant violated: subtotal − discount − comp + tax != grand_total');
        }
    }

    /**
     * LAUNCH-P4 — the order's `prices_include_tax` flag (absent = false).
     *
     * @param  array<string, mixed>  $order
     */
    public static function pricesIncludeTax(array $order): bool
    {
        return filter_var($order['prices_include_tax'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * LAUNCH-P3 P3-6 — the frozen inventory copies of an order being
     * re-written (re-hold, finalize, transfer), keyed by line identity
     * ({@see lineKey()}): the recipe and component copies, and each add-on's
     * stock-use copies. Read before the purge; a re-sent line that matches
     * takes one ({@see takeKeptLineCopy()}), so an unchanged line keeps the
     * recipe it was sold with even after a later recipe edit.
     *
     * Fix order 1 L2 — when the re-send has fewer lines of a key than the
     * order had (an identical line was removed), the NEWEST copies are the
     * ones kept: the removed line is taken to be the older one. The kept
     * copies stay in line order, so a re-send of every line keeps each
     * line's own copy.
     *
     * @param  list<array<string, mixed>>  $incomingLines  the re-send's lines
     * @return array<string, list<array{recipe: mixed, components: mixed, addons: array<int, list<array<string, mixed>>>}>>
     */
    private function keptLineCopies(Order $order, array $incomingLines): array
    {
        $copies = [];
        $items = OrderItem::query()->where('order_id', $order->id)->with('addons')->orderBy('id')->get();
        // LAUNCH-P4 — a combo line's children travel with it: they are part
        // of the line's identity (its choices) and keep their own copies.
        $childrenByParent = $items->filter(static fn (OrderItem $i): bool => $i->parent_order_item_id !== null)
            ->groupBy('parent_order_item_id');
        foreach ($items->filter(static fn (OrderItem $i): bool => $i->parent_order_item_id === null) as $item) {
            $children = [];
            $combo = [];
            foreach ($childrenByParent->get($item->id, collect()) as $child) {
                $perCombo = (float) $item->qty > 0 ? (float) $child->qty / (float) $item->qty : (float) $child->qty;
                $childKey = $this->childKey((int) $child->combo_slot_id, (int) $child->product_id, $perCombo, $child->addons->pluck('add_on_id')->all());
                $children[$childKey][] = [
                    'recipe' => $child->recipe_snapshot_json,
                    'components' => $child->component_snapshot_json,
                    'addons' => $this->addonCopies($child),
                    'cooking_minutes' => $child->cooking_minutes !== null ? (int) $child->cooking_minutes : null,
                ];
                $combo[] = $childKey;
            }

            $key = $this->lineKey((int) $item->product_id, $item->qty, $item->addons->pluck('add_on_id')->all(), $combo);
            $copies[$key][] = [
                'recipe' => $item->recipe_snapshot_json,
                'components' => $item->component_snapshot_json,
                'addons' => $this->addonCopies($item),
                'children' => $children,
                'cooking_minutes' => $item->cooking_minutes !== null ? (int) $item->cooking_minutes : null,
            ];
        }

        $incoming = [];
        foreach ($incomingLines as $line) {
            $key = $this->incomingLineKey($line);
            $incoming[$key] = ($incoming[$key] ?? 0) + 1;
        }
        foreach ($copies as $key => $list) {
            $surplus = count($list) - ($incoming[$key] ?? 0);
            if ($surplus > 0) {
                $copies[$key] = array_slice($list, $surplus);
            }
        }

        return $copies;
    }

    /**
     * Fix order 1 L1 — the moment new or changed lines of a re-sent order
     * copy at: this event's moment, but never earlier than the order's last
     * accepted write (those lines did not exist when it was made). When this
     * event's client time does not move past that write's client time, the
     * device did not stamp the moment of the change — the handheld stamps
     * every order.hold / order.transfer with the order's open time — so the
     * time the server accepted that write is the floor instead. Never later
     * than now.
     */
    private function resendMoment(Order $existing, SyncEvent $event, CarbonInterface $moment): CarbonInterface
    {
        $previous = $existing->client_event_id === null ? null : SyncEvent::query()
            ->where('client_event_id', $existing->client_event_id)
            ->when($existing->device_id !== null, static fn ($q) => $q->where('device_id', $existing->device_id))
            ->first(['client_timestamp', 'server_received_at']);
        if ($previous?->client_timestamp === null) {
            return $moment;
        }

        $floor = CarbonImmutable::instance($previous->client_timestamp)->utc();
        $client = $event->client_timestamp !== null ? CarbonImmutable::instance($event->client_timestamp)->utc() : null;
        if (($client === null || $client->lessThanOrEqualTo($floor)) && $previous->server_received_at !== null) {
            $received = CarbonImmutable::instance($previous->server_received_at)->utc();
            $floor = $received->greaterThan($floor) ? $received : $floor;
        }

        $now = CarbonImmutable::now()->utc();
        $floor = $floor->greaterThan($now) ? $now : $floor;

        return $floor->greaterThan($moment) ? $floor : $moment;
    }

    /**
     * Fix order 1 L2 — a kept add-on copy of a product-as-add-on takes the
     * product's CURRENT stock_mode and keeps its frozen recipe only while
     * that mode is 'ingredient' (made-to-order): a product switched to
     * cooked or unit moves its shelf instead, an untracked one nothing.
     *
     * @param  array<string, mixed>  $addon
     * @return array<string, mixed>
     */
    private function withCurrentProductType(array $addon, int|string|null $companyId): array
    {
        $snapshot = $addon['product_snapshot_json'] ?? null;
        if (! is_array($snapshot) || ! isset($snapshot['product_id'])) {
            return $addon;
        }

        $mode = Product::withTrashed()->where('company_id', $companyId)->whereKey((int) $snapshot['product_id'])->value('stock_mode');
        if ($mode === null) {
            return $addon;
        }
        $snapshot['stock_mode'] = (string) $mode;
        if ($mode !== 'ingredient') {
            $snapshot['recipe'] = null;
        }
        $addon['product_snapshot_json'] = $snapshot;

        return $addon;
    }

    /**
     * The kept copy for this incoming line (first unused match), or null when
     * the line is new or changed.
     *
     * @param  array<string, list<array{recipe: mixed, components: mixed, addons: array<int, list<array<string, mixed>>>}>>  $kept
     * @param  array<string, mixed>  $line
     * @return array{recipe: mixed, components: mixed, addons: array<int, list<array<string, mixed>>>}|null
     */
    private function takeKeptLineCopy(array &$kept, array $line): ?array
    {
        $key = $this->incomingLineKey($line);

        return ($kept[$key] ?? []) !== [] ? array_shift($kept[$key]) : null;
    }

    /**
     * @param  array<string, mixed>  $line  an incoming payload line
     */
    private function incomingLineKey(array $line): string
    {
        return $this->lineKey(
            (int) $line['product_id'],
            $line['qty'],
            array_map(static fn (array $addon): int => (int) ($addon['add_on_id'] ?? 0), $line['addons'] ?? []),
            array_map(fn (array $component): string => $this->incomingChildKey($component), $line['combo'] ?? []),
        );
    }

    /**
     * A line's identity for the re-send rule: what its stock use depends on —
     * the product, the quantity and the add-on set (and, LAUNCH-P4, a
     * combo's choices). Price, discount and note edits leave the line
     * "unchanged".
     *
     * @param  list<int|string>  $addOnIds
     * @param  list<string>  $comboKeys  the combo's choice keys ({@see childKey()})
     */
    private function lineKey(int $productId, mixed $qty, array $addOnIds, array $comboKeys = []): string
    {
        $ids = array_map('intval', $addOnIds);
        sort($ids);
        sort($comboKeys);

        return $productId.'|'.number_format((float) $qty, 3, '.', '').'|'.implode(',', $ids)
            .($comboKeys === [] ? '' : '|'.implode(';', $comboKeys));
    }

    /**
     * LAUNCH-P4 — one combo choice's identity: slot, product, quantity per
     * ONE combo and its add-on set.
     *
     * @param  list<int|string>  $addOnIds
     */
    private function childKey(int $slotId, int $productId, float $qtyPerCombo, array $addOnIds): string
    {
        $ids = array_map('intval', $addOnIds);
        sort($ids);

        return $slotId.':'.$productId.':'.number_format($qtyPerCombo, 3, '.', '').':'.implode(',', $ids);
    }

    /** @param array<string, mixed> $component an incoming combo choice */
    private function incomingChildKey(array $component): string
    {
        return $this->childKey(
            (int) $component['slot_id'],
            (int) $component['product_id'],
            (float) $component['qty'],
            array_map(static fn (array $addon): int => (int) ($addon['add_on_id'] ?? 0), $component['addons'] ?? []),
        );
    }

    /**
     * The kept copy for this incoming combo choice of a kept (unchanged) line.
     *
     * @param  array<string, mixed>  $kept
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>|null
     */
    private function takeKeptChildCopy(array &$kept, array $component): ?array
    {
        $key = $this->incomingChildKey($component);

        return ($kept['children'][$key] ?? []) !== [] ? array_shift($kept['children'][$key]) : null;
    }

    /**
     * Each add-on's frozen stock-use copies, by add-on id, in row order.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function addonCopies(OrderItem $item): array
    {
        $addons = [];
        foreach ($item->addons->sortBy('id') as $addon) {
            $addons[(int) $addon->add_on_id][] = [
                'ingredient_snapshot_json' => $addon->ingredient_snapshot_json,
                'linked_product_id' => $addon->linked_product_id !== null ? (int) $addon->linked_product_id : null,
                'product_snapshot_json' => $addon->product_snapshot_json,
                'consumption_snapshot_json' => $addon->consumption_snapshot_json,
            ];
        }

        return $addons;
    }

    /**
     * P-G2 hardening — freeze the parent line's physical-item components
     * ({product_id, qty} per ONE unit, the same shape as the `components`
     * slice inside product_snapshot_json) so pay AND void consume/restock
     * the set the order was written with, immune to later component edits.
     * Empty [] (not NULL) when the product has no components — NULL is
     * reserved for rows written before this column existed, which
     * ConsumeInventoryAction still serves via the legacy live read.
     *
     * @return list<array{product_id: int, qty: float}>
     */
    private function snapshotComponents(int $productId): array
    {
        return DB::table('pos_product_components')
            ->where('product_id', $productId)
            ->get()
            ->map(static fn ($c): array => [
                'product_id' => (int) $c->component_product_id,
                'qty' => (float) $c->quantity,
            ])
            ->all();
    }

    /**
     * P-G3 — freeze the product behind a product-as-add-on at create time:
     * {product_id, stock_mode, recipe}. Consumption at pay follows the
     * frozen stock_mode — cooked/unit: branch shelf -1 per parent unit;
     * ingredient (made-to-order): the frozen recipe; untracked: nothing.
     * Cooked deliberately freezes NO recipe (production already consumed
     * the ingredients — the same rule as the line's own recipe copy, which
     * also explodes prep items and reads the recipe at the sale moment).
     *
     * @return array{product_id: int, stock_mode: string, recipe: list<array{ingredient_id: int, qty: float, unit: string|null, unit_cost: float}>|null}|null
     */
    private function snapshotAddonProduct(AddOn $addOn, int|string|null $companyId, RecipeCopy $copy): ?array
    {
        if ($addOn->linked_product_id === null) {
            return null;
        }

        $product = Product::withTrashed()
            ->where('company_id', $companyId)
            ->find((int) $addOn->linked_product_id);
        if ($product === null) {
            return null;
        }

        return [
            'product_id' => (int) $product->id,
            'stock_mode' => (string) $product->stock_mode,
            'recipe' => $copy->productRecipe($product),
            // PD3b — the linked product's OWN components (its packaging:
            // a side-fries product's box) now ride the freeze and leave
            // branch stock at pay. Frozen, unlike the parent line's live
            // component read — everything else in this snapshot already is.
            'components' => DB::table('pos_product_components')
                ->where('product_id', (int) $product->id)
                ->get()
                ->map(static fn ($c): array => [
                    'product_id' => (int) $c->component_product_id,
                    'qty' => (float) $c->quantity,
                ])
                ->all() ?: null,
        ];
    }
}
