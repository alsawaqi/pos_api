<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\Sync\Handlers\PayOrderHandler;
use App\Actions\Device\VerifyManagerPinAction;
use App\Events\DeviceSyncBroadcast;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SyncEvent;
use App\Models\TabletOrder;
use App\Support\Money;
use App\Support\Staff\AuthorizationGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Manager payment review for a stuck QR quick order, on an attended device.
 * "paid" records the money through the ordinary order.pay handler (numbering,
 * stock, loyalty, geofence); "not_paid" clears the stuck charge so the order
 * can be paid again or cancelled. The reference and every prior charge fact are
 * kept in the audit event; the PIN is never stored.
 */
final class ReviewQuickPaymentAction
{
    public const EVENT_TYPE = 'qr.quick.payment_review';

    public function __construct(
        private readonly PresentQrPendingOrderAction $present,
        private readonly QrChargeRecoveryGuard $recovery,
        private readonly VerifyManagerPinAction $manager,
        private readonly PayOrderHandler $pay,
        private readonly AuthorizationGate $approvals,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid, array $input): array
    {
        $this->present->assertAttended($device);
        try {
            $approver = $this->manager->verify($device, $input['pin']);
        } catch (RuntimeException) {
            throw new QrChargeException('invalid_pin', 401, 'Manager PIN not accepted.');
        }
        // A retry may carry a fresh GPS fix; only the decision itself is compared.
        $payload = ['order_uuid' => $uuid] + array_diff_key($input, ['pin' => true, 'gps' => true]);

        [$result, $payEvent, $paid] = DB::transaction(function () use ($device, $uuid, $input, $payload, $approver): array {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->present->assertAttended($device);
            $prior = SyncEvent::query()->where('device_id', $device->id)
                ->where('client_event_id', $input['client_request_id'])->lockForUpdate()->first();
            if ($prior !== null) {
                if ($prior->event_type !== self::EVENT_TYPE || $prior->payload_json != $payload
                    || ($prior->result_json['company_id'] ?? null) !== (int) $device->company_id
                    || ($prior->result_json['branch_id'] ?? null) !== (int) $device->branch_id) {
                    throw new QrChargeException('idempotency_conflict', 409, 'This request reference has already been used.');
                }

                return [array_replace($prior->result_json, ['replayed' => true]), null, null];
            }

            $order = Order::query()->where('uuid', $uuid)->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->whereNull('table_id')
                ->where(fn ($kinds) => $kinds->where(fn ($qr) => $qr->where('source', Order::SOURCE_QR_WEB)->where('order_type', 'quick'))
                    // LAUNCH-P6 fix order 3 (F-13) — a customer tablet's Quick / To go order (its tablet row).
                    ->orWhere(fn ($tablet) => $tablet->whereIn('order_type', ['quick', 'to_go'])->whereNull('qr_session_id')
                        ->whereIn('id', TabletOrder::query()->select('order_id')->where('company_id', (int) $device->company_id))))
                ->lockForUpdate()->first();
            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found in this branch.');
            }
            if (! in_array($order->status, [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT], true)) {
                throw new QrChargeException('order_not_unpaid', 409, 'This order is no longer unpaid. Refresh the list.');
            }
            if ($order->transferred_to_device_id !== null) {
                throw new QrChargeException('order_not_editable', 409, 'This order is addressed to another device.');
            }
            $at = now();
            $charge = $this->present->charge($order, $at);
            // Only an in-flight claim waits; withLiveClaim also counts the uncertain outcome under review.
            if ($charge === 'live_claim') {
                throw new QrChargeException('charge_already_claimed', 409, 'A payment is in progress. Wait for its result.');
            }
            if ($order->payments()->exists()) {
                throw new QrChargeException('payment_already_recorded', 409, 'A payment for this order is already recorded. Use the reconciliation queue.');
            }
            if ($charge !== 'uncertain' && ($input['local_attempt_ids'] ?? []) === []) {
                throw new QrChargeException('nothing_to_review', 409, 'This order has no payment to review.');
            }

            $previous = [];
            foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
                $previous[$field] = $order->getRawOriginal($field);
            }
            $statusBefore = (string) $order->status;
            $payEvent = null;
            $paid = null;
            if ($input['decision'] === 'paid') {
                $total = Money::toBaisas($order->grand_total);
                if ($total <= 0 || (int) $input['amount_baisas'] !== $total) {
                    throw new QrChargeException('amount_mismatch', 409, 'The amount must equal the bill total. Refresh and review again.');
                }
                // An ambiguous charge keeps its facts (attended recovery, as the
                // counter fallback does); any other residue cannot block the pay.
                $updates = ['status' => Order::STATUS_HELD];
                if (! $this->recovery->isAmbiguousCharge($order, $at)) {
                    $updates += array_fill_keys(PresentQrPendingOrderAction::CHARGE_FIELDS, null);
                }
                $order->update($updates);
                $card = $input['method'] === Payment::METHOD_CARD;
                $payEvent = SyncEvent::query()->create([
                    'client_event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'company_id' => $device->company_id, 'branch_id' => $device->branch_id, 'event_type' => 'order.pay',
                    'payload_json' => array_filter([
                        'order_uuid' => $order->uuid, 'paid_at' => $at->toIso8601String(),
                        // A card taken elsewhere is recorded for the bank-file match, not as confirmed money.
                        'payments' => [array_filter(['method' => $input['method'], 'amount_baisas' => $total,
                            'status' => $card ? Payment::STATUS_PENDING_RECONCILIATION : Payment::STATUS_SUCCESS,
                            'softpos_reference' => $card ? mb_substr($input['reference'], 0, 64) : null])],
                        'payment_review_request_id' => $input['client_request_id'],
                        'gps' => isset($input['gps']) ? ['lat' => (float) $input['gps']['lat'], 'lng' => (float) $input['gps']['lng']] : null,
                    ], fn ($value): bool => $value !== null),
                    'client_timestamp' => $at, 'server_received_at' => $at, 'ack_status' => SyncEvent::STATUS_RECEIVED,
                ]);
                try {
                    $paid = $this->pay->handle($payEvent, $device);
                } catch (PDOException $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    throw new QrChargeException(str_contains($e->getMessage(), 'GPS fix is required') ? 'gps_required' : 'payment_refused',
                        409, $e->getMessage());
                }
                $payEvent->update(['ack_status' => SyncEvent::STATUS_PROCESSED, 'processed_at' => now(), 'result_json' => $paid]);
            } else {
                $updates = $order->status === Order::STATUS_HELD ? [] : ['status' => Order::STATUS_HELD];
                foreach ($previous as $field => $value) {
                    if ($value !== null) {
                        $updates[$field] = null;
                    }
                }
                if ($updates !== []) {
                    $order->update($updates);
                }
            }
            $order->refresh();
            $result = ['company_id' => (int) $device->company_id, 'branch_id' => (int) $device->branch_id,
                'order_uuid' => $order->uuid, 'order_id' => (int) $order->id, 'decision' => $input['decision'],
                'method' => $input['method'] ?? null, 'amount_baisas' => isset($input['amount_baisas']) ? (int) $input['amount_baisas'] : null,
                'reference' => $input['reference'], 'status_before' => $statusBefore, 'charge_before' => $charge,
                'previous_charge' => $previous, 'status' => (string) $order->status,
                'receipt_number' => $order->receipt_number, 'temp_reference' => $order->temp_reference,
                'payment_ids' => $paid['payment_ids'] ?? [], 'pay_event_id' => $payEvent?->client_event_id,
                'approved_by_staff_id' => (int) $approver->id, 'approved_by' => $approver->name, 'replayed' => false];
            SyncEvent::query()->create(['client_event_id' => $input['client_request_id'], 'device_id' => $device->id, 'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
                'event_type' => self::EVENT_TYPE, 'payload_json' => $payload, 'client_timestamp' => $at,
                'server_received_at' => $at, 'processed_at' => $at, 'ack_status' => SyncEvent::STATUS_PROCESSED,
                'result_json' => $result]);
            // LAUNCH-P5 — the approvals record of this PIN-verified action.
            $this->approvals->recordOnline($device, 'qr.payment_review', 'order', (string) $order->uuid,
                isset($input['amount_baisas']) ? (int) $input['amount_baisas'] : null, (int) $approver->id,
                isset($input['staff_id']) ? (int) $input['staff_id'] : null, (string) $input['client_request_id']);

            return [$result, $payEvent, $paid];
        }, 5);

        if ($result['replayed']) {
            return $result;
        }
        Log::info('qr-payment review', ['order_uuid' => $result['order_uuid'], 'decision' => $result['decision'],
            'device_id' => (int) $device->id, 'approved_by_staff_id' => $result['approved_by_staff_id'],
            'previous' => $result['previous_charge']]);
        if ($payEvent !== null) {
            // Same post-commit work as a synced payment; never fails the committed review.
            try {
                $this->pay->afterSyncEventCommit($payEvent, $device, $paid);
            } catch (Throwable $e) {
                Log::warning('payment review post-commit hook failed', ['error' => $e->getMessage()]);
            }
            try {
                event(DeviceSyncBroadcast::fromProcessed($payEvent->refresh(), $device));
            } catch (Throwable) {
                // best-effort, as for every synced payment
            }
        }

        return $result;
    }
}
