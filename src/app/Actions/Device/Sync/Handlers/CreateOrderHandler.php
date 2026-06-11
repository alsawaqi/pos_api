<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\AddOn;
use App\Models\Branch;
use App\Models\CompReason;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Support\Money;
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
 * product's current recipe so the pay-time stock deduction is immune to
 * later recipe edits. Wire money is integer baisas → decimal OMR via
 * {@see Money}.
 */
class CreateOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly GeofenceGuard $geofence,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        return $this->writeOrder($event, $device, Order::STATUS_OPEN, enforceGeofence: true);
    }

    /**
     * Phase C2 — the shared write path for order.create (status=open,
     * geofenced) and order.hold (status=held, NO geofence: a hold moves no
     * money or stock and must mirror even when queued offline without a GPS
     * fix; order.pay re-checks the fence).
     *
     * UPSERT-BY-UUID: a same-uuid NON-terminal row is replaced in place — a
     * re-hold refreshes the mirror, and the finalize order.create flips a held
     * mirror to open (the device cannot know offline whether its earlier
     * order.hold reached the server). A terminal row (paid/void/refunded)
     * fails the event instead.
     */
    public function writeOrder(SyncEvent $event, Device $device, string $status, bool $enforceGeofence): array
    {
        $order = (array) ($event->payload_json['order'] ?? null);
        $this->validate($order, $event->event_type);
        $this->assertMoneyInvariant($order);
        $this->assertReferencesInTenant($order, $device);
        if ($enforceGeofence) {
            $this->enforceGeofence($order, $device);
        }

        return DB::transaction(function () use ($order, $device, $event, $status): array {
            $existing = Order::query()->where('uuid', $order['uuid'])->lockForUpdate()->first();
            if ($existing !== null
                && ((int) $existing->company_id !== (int) $device->company_id
                    || (int) $existing->branch_id !== (int) $device->branch_id)) {
                // §9.11 — a uuid squatted by another tenant/branch can neither
                // be read nor overwritten; fail without leaking its contents.
                throw new RuntimeException('order uuid already exists outside the device tenant');
            }
            if ($existing !== null
                && in_array($existing->status, [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED], true)) {
                throw new RuntimeException(sprintf('order %s already exists in terminal status %s', $order['uuid'], $existing->status));
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
                'opened_at' => Carbon::parse((string) $order['opened_at']),
                'client_event_id' => $event->client_event_id,
                'note' => $order['note'] ?? null,
                // P-F8 — the printed receipt number (server-allocated via
                // /device/orders/next-number, or the device's offline local
                // fallback). OPTIONAL: orders queued offline / with
                // numbering disabled carry none. Trimmed; empty → NULL.
                'receipt_number' => $this->receiptNumber($order),
            ];

            if ($existing !== null) {
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
                $product = Product::query()->where('company_id', $device->company_id)->find($productId);

                $item = OrderItem::create([
                    'order_id' => $model->id,
                    'product_id' => $productId,
                    'product_name_snapshot' => $product?->name ?? ('#'.$productId),
                    'qty' => $line['qty'],
                    'unit_price_snapshot' => Money::toOmr((int) $line['unit_price_baisas']),
                    'line_discount' => Money::toOmr((int) ($line['line_discount_baisas'] ?? 0)),
                    'line_total' => Money::toOmr((int) $line['line_total_baisas']),
                    'recipe_snapshot_json' => $this->snapshotRecipe($productId),
                    'status' => OrderItem::STATUS_OPEN,
                    'notes' => $line['notes'] ?? null,
                ]);
                $itemIds[$index] = (int) $item->id;

                foreach ($line['addons'] ?? [] as $addon) {
                    $addOnId = (int) $addon['add_on_id'];
                    $addOn = AddOn::query()->where('company_id', $device->company_id)->find($addOnId);
                    OrderItemAddon::create([
                        'order_item_id' => $item->id,
                        'add_on_id' => $addOnId,
                        'add_on_name_snapshot' => $addOn?->name ?? ('#'.$addOnId),
                        'price_delta_snapshot' => Money::toOmr((int) ($addon['price_delta_baisas'] ?? 0)),
                        'ingredient_snapshot_json' => $this->snapshotAddonIngredient($addOn),
                    ]);
                }
            }

            $discountCount = $this->writeDiscounts($order, $model, $device, $itemIds);
            $compCount = $this->writeComps($order, $model, $device, $itemIds);

            return [
                'order_id' => (int) $model->id,
                'order_uuid' => $model->uuid,
                'status' => $model->wasRecentlyCreated ? 'created' : 'updated',
                'order_status' => $status,
                'discounts' => $discountCount,
                'comps' => $compCount,
            ];
        });
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

        $productIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['product_id'],
            $order['lines'],
        )));
        $owned = Product::query()->where('company_id', $companyId)->whereIn('id', $productIds)->pluck('id')->all();
        $foreign = array_diff($productIds, array_map('intval', $owned));
        if ($foreign !== []) {
            throw new RuntimeException('order references product(s) outside the device tenant: '.implode(',', $foreign));
        }

        $addOnIds = [];
        foreach ($order['lines'] as $line) {
            foreach ($line['addons'] ?? [] as $addon) {
                $addOnIds[] = (int) $addon['add_on_id'];
            }
        }
        $addOnIds = array_values(array_unique($addOnIds));
        if ($addOnIds !== []) {
            $ownedAddOns = AddOn::query()->where('company_id', $companyId)->whereIn('id', $addOnIds)->pluck('id')->all();
            $foreignAddOns = array_diff($addOnIds, array_map('intval', $ownedAddOns));
            if ($foreignAddOns !== []) {
                throw new RuntimeException('order references add-on(s) outside the device tenant: '.implode(',', $foreignAddOns));
            }
        }

        $customerId = isset($order['customer_id']) ? (int) $order['customer_id'] : null;
        if ($customerId !== null
            && ! Customer::query()->where('company_id', $companyId)->whereKey($customerId)->exists()) {
            throw new RuntimeException('order references a customer outside the device tenant');
        }

        $tableId = isset($order['table_id']) ? (int) $order['table_id'] : null;
        if ($tableId !== null
            && ! Table::query()->where('company_id', $companyId)->whereKey($tableId)->exists()) {
            throw new RuntimeException('order references a table outside the device tenant');
        }
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
                'discount_id' => $rule?->id ?? $discountId,
                'name_snapshot' => $rule?->name ?? (string) $d['name'],
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
     * @param  array<string, mixed>  $order
     * @param  array<int, int>  $itemIds  line index → created order_item id
     */
    private function writeComps(array $order, Order $model, Device $device, array $itemIds): int
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
        foreach ($comps as $c) {
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
                $reason = CompReason::query()
                    ->where('company_id', $device->company_id)
                    ->find($reasonId);
                if ($reason === null) {
                    throw new RuntimeException('order references a comp reason outside the device tenant: '.$reasonId);
                }

                if ($reason->max_amount !== null && $amountBaisas > (int) round(((float) $reason->max_amount) * 1000)) {
                    throw new RuntimeException(sprintf(
                        'comp exceeds the "%s" cap of %s OMR',
                        $reason->name,
                        (string) $reason->max_amount,
                    ));
                }
            }

            $lineIndex = isset($c['line_index']) ? (int) $c['line_index'] : null;
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
                'approved_by_pos_staff_id' => $c['staff_id'] ?? null,
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
     * outside the branch geofence. Skipped when no GPS is supplied (the
     * device-layer guard is primary) or the branch has no coordinates.
     *
     * @param  array<string, mixed>  $order
     */
    private function enforceGeofence(array $order, Device $device): void
    {
        $branch = Branch::find($device->branch_id);
        // No branch row, or the branch has no fence configured -> nothing to
        // enforce.
        if ($branch === null || ! $this->geofence->isFenced($branch)) {
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
            'source' => ['required', 'string', 'in:'.implode(',', Order::SOURCES)],
            'subtotal_baisas' => ['required', 'integer', 'min:0'],
            'discount_total_baisas' => ['required', 'integer', 'min:0'],
            'tax_total_baisas' => ['required', 'integer', 'min:0'],
            'grand_total_baisas' => ['required', 'integer', 'min:0'],
            'opened_at' => ['required', 'date'],
            // P-F8 — optional printed receipt number (server-allocated or
            // the device's offline fallback); column is varchar(24).
            'receipt_number' => ['nullable', 'string', 'max:24'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_baisas' => ['required', 'integer', 'min:0'],
            'lines.*.line_total_baisas' => ['required', 'integer', 'min:0'],
            'discounts' => ['sometimes', 'array'],
            'discounts.*.discount_id' => ['nullable', 'integer'],
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
            'comps.*.note' => ['nullable', 'string'],
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
        $expected = (int) $order['subtotal_baisas']
            - (int) $order['discount_total_baisas']
            - (int) ($order['comp_total_baisas'] ?? 0)
            + (int) $order['tax_total_baisas'];
        if (abs($expected - (int) $order['grand_total_baisas']) > 1) {
            throw new RuntimeException('order money invariant violated: subtotal − discount − comp + tax != grand_total');
        }
    }

    /**
     * The product's current recipe, frozen for COGS + stock deduction.
     *
     * @return list<array{ingredient_id: int, qty: float, unit: string, unit_cost: float}>|null
     */
    private function snapshotRecipe(int $productId): ?array
    {
        $rows = DB::table('pos_product_recipes')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $costs = Ingredient::query()
            ->whereIn('id', $rows->pluck('ingredient_id')->all())
            ->pluck('default_unit_cost', 'id');

        return $rows->map(fn ($r): array => [
            'ingredient_id' => (int) $r->ingredient_id,
            'qty' => (float) $r->quantity,
            'unit' => $r->unit_at_set,
            'unit_cost' => (float) ($costs[$r->ingredient_id] ?? 0),
        ])->all();
    }

    /**
     * @return array{ingredient_id: int, qty: float, unit: string|null, unit_cost: float}|null
     */
    private function snapshotAddonIngredient(?AddOn $addOn): ?array
    {
        if ($addOn === null || $addOn->ingredient_id === null) {
            return null;
        }

        $cost = Ingredient::query()->whereKey($addOn->ingredient_id)->value('default_unit_cost');

        return [
            'ingredient_id' => (int) $addOn->ingredient_id,
            'qty' => (float) $addOn->ingredient_qty,
            'unit' => $addOn->ingredient_unit,
            'unit_cost' => (float) ($cost ?? 0),
        ];
    }
}
