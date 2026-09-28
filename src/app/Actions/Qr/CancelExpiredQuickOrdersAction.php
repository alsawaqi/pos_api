<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\VerifyManagerPinAction;
use App\Actions\Orders\VoidOrderCoreAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Online, snapshot-confirmed cancellation. No payment result or PIN is journaled. */
final class CancelExpiredQuickOrdersAction
{
    public function __construct(
        private readonly PresentQrPendingOrderAction $present,
        private readonly VerifyManagerPinAction $manager,
        private readonly VoidOrderCoreAction $void,
        private readonly QuickOrderCancellationWasteAction $waste,
    ) {}

    private function orders(Device $device)
    {
        return Order::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->where('source', Order::SOURCE_QR_WEB)->where('order_type', 'quick')->whereNull('table_id');
    }

    private function expired(?QrSession $session): bool
    {
        return $session !== null && (in_array($session->status, ['closed', 'expired'], true)
            || $session->expires_at?->lte(now()) === true);
    }

    private function admissible(Order $order, ?QrSession $session): void
    {
        if (! in_array($order->status, [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT], true)) {
            throw new QrChargeException('void_bill_not_unpaid', 409, 'This order is no longer unpaid.');
        }
        if (! $this->expired($session)) {
            throw new QrChargeException('qr_session_active', 409, 'The phone session is active or cannot be verified.');
        }
        if (! in_array($this->present->charge($order, now()), ['none', 'declined', 'cancelled'], true)
            || $order->payments()->exists() || $order->transferred_to_device_id !== null) {
            throw new QrChargeException('qr_charge_recovery_required', 409, 'Resolve payment evidence or the transfer before cancelling this order.');
        }
    }

    private function session(Device $device, Order $order, bool $lock = false): ?QrSession
    {
        $query = QrSession::query()->whereKey($order->qr_session_id)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function preview(Device $device, ?string $uuid): array
    {
        $this->present->assertAttended($device);
        $orders = $this->orders($device)->when($uuid !== null, fn ($q) => $q->where('uuid', $uuid))
            ->when($uuid === null, fn ($q) => $q->whereIn('status', [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT]))
            ->orderBy('opened_at')->orderBy('id')->with(['items.addons', 'comps'])->get();
        if ($uuid !== null && $orders->isEmpty()) {
            throw new QrChargeException('order_not_found', 404, 'The order was not found in this branch.');
        }
        $rows = [];
        $proof = [];
        foreach ($orders as $order) {
            $session = $this->session($device, $order);
            if ($uuid === null && ! $this->expired($session)) {
                continue;
            }
            $this->admissible($order, $session);
            $items = $order->items->filter(fn ($item): bool => $item->status !== 'void' && (float) $item->qty > 0);
            $prepared = $this->waste->preparedIds($order);
            $rows[] = ['uuid' => $order->uuid, 'reference' => $order->receipt_number ?? $order->temp_reference ?? $order->uuid,
                'total_baisas' => Money::toBaisas($order->grand_total), 'prepared' => $prepared !== [],
                'items' => $items->map(fn ($item): array => ['name' => $item->product_name_snapshot, 'qty' => (float) $item->qty])->values()->all()];
            $proof[] = ['uuid' => $order->uuid, 'revision' => QuickQrWorkspaceAction::revision($order), 'prepared_ids' => $prepared];
        }
        $token = Crypt::encryptString(json_encode(['device_id' => (int) $device->id,
            'company_id' => (int) $device->company_id, 'branch_id' => (int) $device->branch_id,
            'expires' => now()->addMinutes(5)->timestamp, 'orders' => $proof], JSON_THROW_ON_ERROR));

        return ['orders' => $rows, 'count' => count($rows), 'total_baisas' => array_sum(array_column($rows, 'total_baisas')), 'preview_token' => $token];
    }

    public function cancel(Device $device, array $input): array
    {
        $this->present->assertAttended($device);
        try {
            $approver = $this->manager->verify($device, $input['pin']);
        } catch (RuntimeException) {
            throw new QrChargeException('invalid_pin', 401, 'Manager PIN not accepted.');
        }
        $payload = array_diff_key($input, ['pin' => true]);
        sort($payload['prepared_order_uuids']);

        return DB::transaction(function () use ($device, $approver, $input, $payload): array {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->present->assertAttended($device);
            $prior = SyncEvent::query()->where('client_event_id', $input['client_request_id'])->lockForUpdate()->first();
            if ($prior !== null) {
                if ((int) $prior->device_id !== (int) $device->id || $prior->event_type !== 'qr.quick.cancel_expired'
                    || $prior->payload_json != $payload || ($prior->result_json['company_id'] ?? null) !== (int) $device->company_id
                    || ($prior->result_json['branch_id'] ?? null) !== (int) $device->branch_id) {
                    throw new QrChargeException('idempotency_conflict', 409, 'This request reference has already been used.');
                }

                return array_replace($prior->result_json, ['replayed' => true]);
            }
            try {
                $proof = json_decode(Crypt::decryptString($input['preview_token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new QrChargeException('void_preview_changed', 409, 'Refresh the cancellation preview.');
            }
            if (($proof['device_id'] ?? null) !== (int) $device->id || ($proof['company_id'] ?? null) !== (int) $device->company_id
                || ($proof['branch_id'] ?? null) !== (int) $device->branch_id || ($proof['expires'] ?? 0) < now()->timestamp
                || ! is_array($proof['orders'] ?? null)) {
                throw new QrChargeException('void_preview_changed', 409, 'Refresh the cancellation preview.');
            }
            $expected = collect($proof['orders'])->keyBy('uuid');
            if (array_diff($payload['prepared_order_uuids'], $expected->keys()->all()) !== []) {
                throw new QrChargeException('void_preview_changed', 409, 'The prepared orders must belong to this review.');
            }
            $orders = $this->orders($device)->whereIn('uuid', $expected->keys())->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== $expected->count()) {
                throw new QrChargeException('order_not_found', 404, 'An order is no longer available in this branch.');
            }
            // Validate the complete frozen batch before any effect; additions after preview are never included.
            foreach ($orders as $order) {
                $this->admissible($order, $this->session($device, $order, true));
                if (! hash_equals($expected[$order->uuid]['revision'], QuickQrWorkspaceAction::revision($order))
                    || $expected[$order->uuid]['prepared_ids'] !== $this->waste->preparedIds($order)) {
                    throw new QrChargeException('void_preview_changed', 409, 'An order changed. Review the list before cancelling.');
                }
            }
            $results = [];
            foreach ($orders as $order) {
                $preparedIds = in_array($order->uuid, $payload['prepared_order_uuids'], true)
                    ? $order->items->where('status', '!=', 'void')->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    : $expected[$order->uuid]['prepared_ids'];
                $waste = $this->waste->handle($device, $order, $preparedIds, (int) $approver->id, $input['client_request_id']);
                $this->void->handle($order, $device, now(), $input['reason']);
                $results[] = ['order_uuid' => $order->uuid, 'status' => 'void', 'waste' => $waste];
            }
            $result = ['company_id' => (int) $device->company_id, 'branch_id' => (int) $device->branch_id, 'orders' => $results, 'count' => count($results), 'replayed' => false,
                'approved_by_staff_id' => (int) $approver->id, 'approved_by' => $approver->name];
            SyncEvent::query()->create(['client_event_id' => $input['client_request_id'], 'device_id' => $device->id,
                'event_type' => 'qr.quick.cancel_expired', 'payload_json' => $payload, 'client_timestamp' => now(),
                'server_received_at' => now(), 'processed_at' => now(), 'ack_status' => SyncEvent::STATUS_PROCESSED,
                'result_json' => $result]);

            return $result;
        }, 5);
    }
}
