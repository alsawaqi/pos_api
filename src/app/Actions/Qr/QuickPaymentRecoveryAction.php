<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Recovery requests never dispatch a payment, settle, or reinterpret bank evidence. */
final class QuickPaymentRecoveryAction
{
    public function __construct(private readonly FallbackQrOrderToCounterAction $fallback) {}

    public function customer(QrSession $credential, ?string $action): array
    {
        return DB::transaction(function () use ($credential, $action): array {
            $order = Order::query()->where('qr_session_id', $credential->id)
                ->where('company_id', $credential->company_id)->where('branch_id', $credential->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)->where('order_type', 'quick')->whereNull('table_id')
                ->latest('id')->lockForUpdate()->first();
            if ($order === null || $credential->isDineIn()) {
                throw new QrChargeException('order_not_found', 404, 'The quick order was not found.');
            }
            $session = QrSession::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();
            if ($action !== null && ($session->status !== QrSession::STATUS_ORDERED || $session->isExpiredAt(now()))) {
                throw new QrChargeException('qr_session_not_active', 409, 'This session can only display payment status.');
            }

            return $this->apply($order, $session, $action, 'customer');
        }, 5);
    }

    public function station(Device $device, string $uuid, ?string $action): array
    {
        if (! $device->isAssigned() || $device->status !== 'active' || ! $device->isPaymentStation()) {
            throw new QrChargeException('device_not_active_station', 409, 'An active payment station is required.');
        }

        return DB::transaction(function () use ($device, $uuid, $action): array {
            $order = Order::query()->where('uuid', $uuid)->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'quick')->whereNull('table_id')->lockForUpdate()->first();
            $session = $order?->qr_session_id === null ? null : QrSession::query()
                ->whereKey($order->qr_session_id)->when((int) $order->transferred_to_device_id !== (int) $device->id, fn ($q) => $q->where('device_id', $device->id))
                ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->lockForUpdate()->first();
            if ($order === null || ($session === null && (int) $order->transferred_to_device_id !== (int) $device->id)
                || ($order->transferred_to_device_id !== null && (int) $order->transferred_to_device_id !== (int) $device->id)) {
                throw new QrChargeException('order_not_found', 404, 'This order does not belong to this station.');
            }

            return $this->apply($order, $session, $action, 'station');
        }, 5);
    }

    private function apply(Order $order, ?QrSession $session, ?string $action, string $source): array
    {
        $state = $this->present($order);
        if ($action === null || ($action === 'counter' && $state['state'] === 'counter')) {
            return $state;
        }
        $allowed = match ($action) {
            'retry' => $state['can_retry'],
            'counter' => $state['can_counter'],
            'review' => $state['can_request_review'],
            default => false,
        };
        if (! $allowed) {
            throw new QrChargeException('payment_recovery_not_safe', 409, 'Check payment status. This action cannot start another payment while the result is unresolved.');
        }
        if ($action === 'counter' && $order->transferred_to_device_id !== null) {
            // The safe-outcome checks above still apply; an uncertain or live
            // tender never loses its evidence. No browser credential is revived.
            $updates = ['status' => Order::STATUS_HELD, 'transferred_to_device_id' => null, 'transferred_at' => null];
            $previous = [];
            foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
                $previous[$field] = $order->getRawOriginal($field);
                $updates[$field] = null;
            }
            $order->update($updates);
            Log::info('qr-transfer returned to counter', ['order_uuid' => $order->uuid, 'previous' => $previous]);

            return $this->present($order);
        }
        if ($action === 'counter') {
            $device = Device::query()->whereKey($session->device_id)->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)->first();
            if ($device === null || ! $device->isAssigned() || ! $device->isPaymentStation() || $device->status !== 'active') {
                throw new QrChargeException('device_not_active_station', 409, 'The original payment station is not active.');
            }
            $this->fallback->handle($device, (string) $order->uuid);
            $order->refresh();
            // This new quick-order handoff has already proved no unresolved charge.
            // The legacy fallback retains a zero round-up marker, which the attended
            // inbox treats as provenance. Clear only that zero after all claim facts
            // were cleared, so the cashier can finish the safe handoff.
            $cleared = $order->status === Order::STATUS_HELD;
            foreach (array_diff(PresentQrPendingOrderAction::CHARGE_FIELDS, ['charge_roundup_amount_baisas']) as $field) {
                $cleared = $cleared && $order->getRawOriginal($field) === null;
            }
            if ($cleared && $order->charge_roundup_amount_baisas === 0) {
                $order->update(['charge_roundup_amount_baisas' => null]);
            }

            return $this->present($order);
        }
        $request = $order->qr_recovery_request;
        // Repeated clicks/transport retries do not create repeated requests.
        if (($request['action'] ?? null) !== $action) {
            $order->update(['qr_recovery_request' => [
                'action' => $action, 'source' => $source, 'requested_at' => now()->toIso8601String(),
            ]]);
            Log::info('qr-payment recovery requested', ['order_uuid' => $order->uuid, 'action' => $action, 'source' => $source]);
        }

        return $this->present($order);
    }

    private function present(Order $order): array
    {
        $empty = true;
        foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
            $value = $order->getRawOriginal($field);
            $empty = $empty && ($value === null || ($field === 'charge_roundup_amount_baisas' && (int) $value === 0));
        }
        $complete = $order->charge_device_id !== null && $order->charge_amount_baisas !== null
            && $order->charge_claimed_at !== null && $order->charge_deadline_at !== null;
        $safeOutcome = $complete && in_array($order->charge_outcome, [Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED], true);
        $hasPayment = $order->payments()->exists();
        $live = Order::query()->whereKey($order->id)->withLiveClaim()->exists();
        $state = match (true) {
            $order->status === Order::STATUS_PAID => 'paid',
            in_array($order->status, [Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_COMBINED], true) => 'closed',
            $hasPayment => 'review_required',
            $order->charge_outcome === Order::CHARGE_OUTCOME_UNCERTAIN => 'review_required',
            $live => 'processing',
            ! $empty && ! $safeOutcome => 'review_required',
            $order->status === Order::STATUS_HELD => 'counter',
            $order->status !== Order::STATUS_AWAITING_PAYMENT => 'review_required',
            $order->charge_outcome === Order::CHARGE_OUTCOME_DECLINED => 'declined',
            $order->charge_outcome === Order::CHARGE_OUTCOME_CANCELLED => 'not_started',
            default => 'ready',
        };

        return [
            'order_uuid' => $order->uuid, 'reference' => $order->receipt_number ?: $order->temp_reference,
            'amount_baisas' => Money::toBaisas($order->grand_total), 'state' => $state,
            'can_retry' => in_array($state, ['declined', 'not_started'], true),
            'can_counter' => in_array($state, ['ready', 'declined', 'not_started'], true),
            'can_request_review' => in_array($state, ['review_required', 'processing'], true),
            'request' => $order->qr_recovery_request,
        ];
    }
}
