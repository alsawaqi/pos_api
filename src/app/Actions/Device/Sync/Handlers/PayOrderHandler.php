<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.3 — processes an `order.pay` sync event: records the tender(s),
 * flips the order to paid, and deducts inventory (§16 "inventory deducts at
 * payment completion").
 *
 * Split tender = several payment rows. A card payment the cashier bypassed
 * on NFC-timeout arrives status=pending_reconciliation and is flagged for
 * the admin reconciliation queue. The order is resolved scoped to the
 * device's company + branch, so a device can't pay another tenant's /
 * branch's order. Invariant: Σ(tendered) == grand_total.
 */
class PayOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ConsumeInventoryAction $inventory,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;
        $payments = $payload['payments'] ?? null;

        if (! is_string($orderUuid) || ! is_array($payments) || $payments === []) {
            throw new RuntimeException('invalid order.pay payload: order_uuid + non-empty payments required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for payment: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_PAID) {
            throw new RuntimeException('order already paid: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID) {
            throw new RuntimeException('cannot pay a voided order: '.$orderUuid);
        }

        $capturedAt = isset($payload['paid_at']) ? Carbon::parse((string) $payload['paid_at']) : now();

        return DB::transaction(function () use ($order, $payments, $capturedAt): array {
            $paymentIds = [];
            $tenderedBaisas = 0;

            foreach ($payments as $tender) {
                if (! isset($tender['method'], $tender['amount_baisas']) || ! in_array($tender['method'], Payment::METHODS, true)) {
                    throw new RuntimeException('invalid payment tender in order.pay');
                }

                $status = $tender['status'] ?? Payment::STATUS_SUCCESS;
                $payment = Payment::create([
                    'uuid' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'method' => $tender['method'],
                    'amount' => Money::toOmr((int) $tender['amount_baisas']),
                    'change_given' => isset($tender['change_given_baisas']) ? Money::toOmr((int) $tender['change_given_baisas']) : null,
                    'softpos_reference' => $tender['softpos_reference'] ?? null,
                    'softpos_auth_code' => $tender['softpos_auth_code'] ?? null,
                    'status' => $status,
                    'pending_reconciliation' => $status === Payment::STATUS_PENDING_RECONCILIATION,
                    'captured_at' => $capturedAt,
                ]);

                $paymentIds[] = (int) $payment->id;
                $tenderedBaisas += (int) $tender['amount_baisas'];
            }

            $grandBaisas = Money::toBaisas($order->grand_total);
            if (abs($tenderedBaisas - $grandBaisas) > 1) {
                throw new RuntimeException('payment total mismatch: tendered '.$tenderedBaisas.' baisas vs grand_total '.$grandBaisas);
            }

            $order->update([
                'status' => Order::STATUS_PAID,
                'closed_at' => $capturedAt,
            ]);

            $movements = $this->inventory->consume($order);

            return [
                'order_id' => (int) $order->id,
                'status' => 'paid',
                'payment_ids' => $paymentIds,
                'movements' => $movements,
            ];
        });
    }
}
