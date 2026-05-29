<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\AddOn;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\SyncEvent;
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
        $order = (array) ($event->payload_json['order'] ?? null);
        $this->validate($order);
        $this->assertMoneyInvariant($order);
        $this->enforceGeofence($order, $device);

        return DB::transaction(function () use ($order, $device, $event): array {
            $model = Order::create([
                'uuid' => $order['uuid'],
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'staff_id' => $order['staff_id'] ?? null,
                'customer_id' => $order['customer_id'] ?? null,
                'table_id' => $order['table_id'] ?? null,
                'order_type' => $order['order_type'],
                'status' => Order::STATUS_OPEN,
                'source' => $order['source'],
                'plate_number' => $order['plate_number'] ?? null,
                'subtotal' => Money::toOmr((int) $order['subtotal_baisas']),
                'discount_total' => Money::toOmr((int) $order['discount_total_baisas']),
                'tax_total' => Money::toOmr((int) $order['tax_total_baisas']),
                'grand_total' => Money::toOmr((int) $order['grand_total_baisas']),
                'opened_at' => Carbon::parse((string) $order['opened_at']),
                'client_event_id' => $event->client_event_id,
                'note' => $order['note'] ?? null,
            ]);

            foreach ($order['lines'] as $line) {
                $productId = (int) $line['product_id'];
                $product = Product::find($productId);

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

                foreach ($line['addons'] ?? [] as $addon) {
                    $addOnId = (int) $addon['add_on_id'];
                    $addOn = AddOn::find($addOnId);
                    OrderItemAddon::create([
                        'order_item_id' => $item->id,
                        'add_on_id' => $addOnId,
                        'add_on_name_snapshot' => $addOn?->name ?? ('#'.$addOnId),
                        'price_delta_snapshot' => Money::toOmr((int) ($addon['price_delta_baisas'] ?? 0)),
                        'ingredient_snapshot_json' => $this->snapshotAddonIngredient($addOn),
                    ]);
                }
            }

            return ['order_id' => (int) $model->id, 'order_uuid' => $model->uuid, 'status' => 'created'];
        });
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
        $gps = $order['gps'] ?? null;
        if (! is_array($gps) || ! isset($gps['lat'], $gps['lng'])) {
            return;
        }

        $branch = Branch::find($device->branch_id);
        if ($branch !== null) {
            $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function validate(array $order): void
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_baisas' => ['required', 'integer', 'min:0'],
            'lines.*.line_total_baisas' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('invalid order.create payload: '.implode('; ', $validator->errors()->all()));
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function assertMoneyInvariant(array $order): void
    {
        $expected = (int) $order['subtotal_baisas'] - (int) $order['discount_total_baisas'] + (int) $order['tax_total_baisas'];
        if (abs($expected - (int) $order['grand_total_baisas']) > 1) {
            throw new RuntimeException('order money invariant violated: subtotal − discount + tax != grand_total');
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
