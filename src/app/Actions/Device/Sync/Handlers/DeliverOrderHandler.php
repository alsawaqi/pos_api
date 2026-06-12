<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G7 — processes an `order.deliver` sync event: closes a delivery-provider
 * order WITHOUT a tender. The provider pays later, minus their commission,
 * so no money moves at the till — the order lands as `pending_verification`
 * and only becomes a sale when the merchant reconciles the provider's
 * statement on the Deliveries page (which flips it to paid and dates the
 * revenue at confirmation).
 *
 * What fires NOW (mirroring order.pay's "the goods left the shop" rule):
 *   - inventory consumption, inside the same transaction;
 *   - the provider/reference snapshot + the expected-payout math
 *     (grand − commission%, both frozen at punch so later provider edits
 *     never rewrite this order).
 * What is DEFERRED to confirmation (the P-F7 deferred-effects rule):
 *   - the sale-commission split (recorded by the merchant's confirm action,
 *     idempotent on the one-breakdown-per-order guard);
 *   - nothing else exists for delivery orders by design: no tax (device
 *     zeroes it), no discounts/offers, no loyalty, no round-up.
 *
 * Payload:
 *   { order_uuid: required,
 *     delivery: { provider_id: required int, reference: required string,
 *                 customer_phone?: string, driver_phone?: string },
 *     delivered_at?: date, gps?: {lat,lng} }
 */
class DeliverOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
        private readonly GeofenceGuard $geofence,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;
        $delivery = $payload['delivery'] ?? null;

        if (! is_string($orderUuid) || ! is_array($delivery)) {
            throw new RuntimeException('invalid order.deliver payload: order_uuid + delivery required');
        }

        $providerId = (int) ($delivery['provider_id'] ?? 0);
        $reference = trim((string) ($delivery['reference'] ?? ''));
        if ($providerId < 1 || $reference === '' || mb_strlen($reference) > 64) {
            throw new RuntimeException('invalid order.deliver payload: provider_id + reference (max 64) required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for delivery: '.$orderUuid);
        }
        if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION, Order::STATUS_VOID, Order::STATUS_REFUNDED], true)) {
            throw new RuntimeException('order already settled: '.$orderUuid);
        }
        if ($order->order_type !== 'delivery') {
            throw new RuntimeException('order.deliver requires a delivery-type order: '.$orderUuid);
        }

        // The provider must belong to this tenant. Soft-deleted providers stay
        // resolvable by design (the order was punched while it existed); the
        // snapshot columns below keep the books readable either way.
        $provider = DB::table('pos_delivery_providers')
            ->where('company_id', $device->company_id)
            ->where('id', $providerId)
            ->first();
        if ($provider === null) {
            throw new RuntimeException('delivery provider not found for this company: '.$providerId);
        }

        // Same fail-closed fence as order.pay: completing a sale (even a
        // no-tender one) must happen at the branch.
        $this->enforceGeofence($device, $payload);

        $punchedAt = isset($payload['delivered_at']) ? Carbon::parse((string) $payload['delivered_at']) : now();

        // Freeze the money math at punch: expected payout = punched grand
        // minus the provider's cut, baisa-rounded.
        $grandBaisas = Money::toBaisas($order->grand_total);
        $commissionPercent = (float) ($provider->commission_percent ?? 0);
        $expectedBaisas = $grandBaisas - (int) round($grandBaisas * $commissionPercent / 100);

        $customerPhone = $this->optionalString($delivery, 'customer_phone', 32);
        $driverPhone = $this->optionalString($delivery, 'driver_phone', 32);

        return DB::transaction(function () use ($order, $provider, $reference, $customerPhone, $driverPhone, $commissionPercent, $expectedBaisas, $punchedAt): array {
            $order->update([
                'status' => Order::STATUS_PENDING_VERIFICATION,
                // NOT closed: closed_at is the revenue stamp and is written
                // at CONFIRMATION (the merchant's Deliveries page), per the
                // "revenue dated to when the money was received" rule.
                'delivery_provider_id' => (int) $provider->id,
                'delivery_provider_name' => (string) $provider->name,
                'delivery_reference' => $reference,
                'delivery_customer_phone' => $customerPhone,
                'delivery_driver_phone' => $driverPhone,
                'delivery_commission_percent' => $commissionPercent,
                'delivery_expected_payout' => Money::toOmr($expectedBaisas),
                'delivery_punched_at' => $punchedAt,
            ]);

            // The food left the shop — consume now, exactly like order.pay.
            // A later void of the pending order reverses this.
            $movements = $this->inventory->consume($order);

            return [
                'order_id' => (int) $order->id,
                'status' => Order::STATUS_PENDING_VERIFICATION,
                'delivery_provider_id' => (int) $provider->id,
                'delivery_reference' => $reference,
                'expected_payout_baisas' => $expectedBaisas,
                'movements' => $movements,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function optionalString(array $delivery, string $key, int $max): ?string
    {
        $value = trim((string) ($delivery[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new RuntimeException('invalid order.deliver payload: '.$key.' too long (max '.$max.')');
        }

        return $value;
    }

    /**
     * Fail-closed geofence, mirroring order.pay: at a fenced branch the
     * device must supply a GPS fix inside the fence.
     *
     * @param  array<string, mixed>  $payload
     */
    private function enforceGeofence(Device $device, array $payload): void
    {
        $branch = Branch::find($device->branch_id);
        if ($branch === null || ! $this->geofence->isFenced($branch)) {
            return;
        }

        $gps = $payload['gps'] ?? null;
        if (! is_array($gps) || ! isset($gps['lat'], $gps['lng'])) {
            throw new RuntimeException('delivery close rejected: a GPS fix is required at this geofenced branch');
        }

        $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
    }
}
