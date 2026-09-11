<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Read-only checkout snapshot. Possessing a UUID is never payment authority. */
final class ReadQrCheckoutAction
{
    public function __construct(private readonly PresentQrPendingOrderAction $present) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid): array
    {
        $this->present->assertAttended($device);

        return DB::transaction(function () use ($device, $uuid): array {
            $order = Order::query()
                ->where('uuid', $uuid)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)
                ->where(function ($query): void {
                    $query->where(fn ($q) => $q->where('order_type', 'quick')->whereNull('table_id'))
                        ->orWhere(fn ($q) => $q->where('order_type', 'dine_in')->whereNotNull('table_id'));
                })
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The QR order was not found.');
            }
            // A read cannot acquire, replay, extend or repair a claim. Unknown
            // payment evidence must go through the existing recovery process.
            if ($order->status !== Order::STATUS_AWAITING_PAYMENT
                || $order->charge_outcome !== null
                || (int) $order->charge_device_id !== (int) $device->getKey()
                || $order->charge_claimed_at === null
                || $order->charge_deadline_at === null
                || $order->charge_deadline_at->lte(now())
                || $order->charge_amount_baisas === null
                || (int) $order->charge_amount_baisas !== Money::toBaisas($order->grand_total)
                || (int) ($order->charge_roundup_amount_baisas ?? 0) !== 0) {
                throw new QrChargeException('qr_checkout_claim_required', 409, 'Reserve this bill on this device before opening checkout.');
            }

            $order->load(['items' => fn ($q) => $q->orderBy('id'), 'items.addons', 'comps' => fn ($q) => $q->orderBy('id')]);
            $customer = $order->customer_id === null ? null : Customer::withTrashed()
                ->whereKey((int) $order->customer_id)
                ->where('company_id', (int) $device->company_id)
                ->first();

            return [
                'order' => $this->present->mapOrder($order),
                'customer' => $customer === null ? null : [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'claim' => [
                    'order_uuid' => (string) $order->uuid,
                    'charge_amount_baisas' => (int) $order->charge_amount_baisas,
                    'charge_claimed_at' => $order->charge_claimed_at->toIso8601String(),
                    'charge_deadline_at' => $order->charge_deadline_at->toIso8601String(),
                ],
            ];
        });
    }
}
