<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Quick-order safe counter routing; deliberately separate from attended recovery. */
final class MoveQuickQrOrderToCounterAction
{
    public function __construct(
        private readonly PresentQrPendingOrderAction $present,
        private readonly QrChargeRecoveryGuard $recovery,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid): array
    {
        // Step 0 must precede even a tenant-scoped order lookup.
        $this->present->assertAttended($device);
        $result = DB::transaction(function () use ($device, $uuid): array {
            $order = Order::query()
                ->where('uuid', $uuid)
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->where('order_type', 'quick')
                ->where('source', Order::SOURCE_QR_WEB)
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }
            $session = $order->qr_session_id === null ? null : QrSession::query()
                ->whereKey($order->qr_session_id)
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->lockForUpdate()->first();
            if ($order->transferred_to_device_id !== null) {
                throw new QrChargeException('order_not_editable', 409, 'This order is addressed to another device.');
            }
            $at = now();
            if (! in_array($order->status, [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT], true)) {
                throw new QrChargeException('order_not_awaiting_payment', 409, 'The order is not awaiting payment.');
            }
            if (Order::query()->whereKey($order->getKey())->withLiveClaim($at)->exists()) {
                throw new QrChargeException('charge_already_claimed', 409, 'The order already has a live charge claim.');
            }
            $empty = $this->present->hasNoChargeProvenance($order);
            if ($this->recovery->isAmbiguousCharge($order, $at)
                || (! $empty && ! in_array($order->charge_outcome, [
                    Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED,
                ], true))) {
                throw new QrChargeException('charge_outcome_uncertain', 409, 'The charge result requires manager recovery.');
            }
            if ($order->status === Order::STATUS_HELD
                && trim((string) $order->temp_reference) === ''
                && trim((string) $order->receipt_number) === '') {
                throw new QrChargeException('order_already_held', 409, 'The held order has no counter reference.');
            }

            $previous = null;
            // Step 6 is a true no-write replay, including updated_at.
            if ($order->status !== Order::STATUS_HELD || ! $empty) {
                $updates = ['status' => Order::STATUS_HELD];
                if (! $empty) {
                    $previous = [];
                    foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
                        $previous[$field] = $order->getRawOriginal($field);
                        $updates[$field] = null;
                    }
                }
                $order->update($updates);
            }
            $order->load(['items' => fn ($q) => $q->orderBy('id'), 'items.addons', 'comps' => fn ($q) => $q->orderBy('id')]);
            $phone = $order->customer_id === null ? null : Customer::withTrashed()
                ->whereKey($order->customer_id)->where('company_id', $device->company_id)->value('phone');
            $row = $this->present->handle($order, $session, $phone, $at);

            return $previous === null ? $row : $row + ['cleared_charge' => $previous];
        }, 5);

        if (isset($result['cleared_charge'])) {
            Log::info('qr-pending to-counter', [
                'order_uuid' => $result['uuid'],
                'device_id' => (int) $device->getKey(),
                'previous' => $result['cleared_charge'],
            ]);
        }

        return $result;
    }
}
