<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\Sync\Handlers\VoidOrderHandler;
use App\Actions\Device\VerifyManagerPinAction;
use App\Actions\Tables\FrozenLegacyTableBill;
use App\Actions\Tables\ResolveStaffSeatingAction;
use App\Actions\Tables\TableReadSnapshot;
use App\Models\Device;
use App\Models\Order;
use App\Models\PosStaff;
use App\Models\SyncEvent;
use App\Models\TableSession;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Online cancellation of the reviewed unpaid bill, never payment recovery. */
final class VoidWorkspaceBillAction
{
    public function __construct(
        private readonly TableReadSnapshot $read,
        private readonly FrozenLegacyTableBill $frozen,
        private readonly ResolveStaffSeatingAction $locks,
        private readonly VerifyManagerPinAction $managers,
        private readonly VoidOrderHandler $void,
    ) {}

    public function preview(Device $device, string $uuid): array
    {
        $this->attended($device);

        return $this->read->handle(function () use ($device, $uuid): array {
            $order = $this->order($device, $uuid);
            $this->eligible($order);
            $snapshot = $this->snapshot($order);

            return [
                'order' => $this->frozen->present($snapshot['bill']),
                'pending_rounds' => count(array_filter($snapshot['bill']['rounds'],
                    fn ($round): bool => $round['status'] === 'pending_confirmation')),
                'preview_token' => Crypt::encryptString(json_encode([
                    'device' => (int) $device->id, 'company' => (int) $device->company_id,
                    'branch' => (int) $device->branch_id, 'uuid' => $uuid,
                    'expires' => now()->addMinutes(5)->timestamp,
                    'snapshot' => $this->fingerprint($snapshot),
                ], JSON_THROW_ON_ERROR)),
            ];
        });
    }

    public function handle(Device $device, string $uuid, array $input): array
    {
        $this->attended($device);
        Validator::make($input, [
            'preview_token' => ['required', 'string', 'max:4096'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'reason' => ['required', 'string', 'max:200'],
        ])->validate();
        if (trim($input['reason']) === '') {
            throw $this->refusal('void_reason_required', 'Enter a cancellation reason.');
        }
        try {
            $proof = json_decode(Crypt::decryptString($input['preview_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->refusal('void_preview_changed', 'Review the bill again before cancelling.');
        }
        if (! is_array($proof) || ($proof['device'] ?? null) !== (int) $device->id
            || ($proof['company'] ?? null) !== (int) $device->company_id
            || ($proof['branch'] ?? null) !== (int) $device->branch_id || ($proof['uuid'] ?? null) !== $uuid
            || ! is_int($proof['expires'] ?? null) || ! is_string($proof['snapshot'] ?? null)) {
            throw $this->refusal('void_preview_changed', 'Review the bill on this device again.');
        }
        try {
            $manager = $this->managers->verify($device, $input['pin']);
        } catch (RuntimeException) {
            throw new QrDineInException('invalid_pin', 401, 'Invalid PIN.');
        }
        $policy = $this->policy($device);
        $initial = $this->order($device, $uuid);
        $cancel = function (Device $current) use ($uuid, $input, $proof, $manager, $policy): array {
            $this->attended($current);
            $freshManager = PosStaff::query()->find($manager->id);
            if ($freshManager === null || $freshManager->getRawOriginal() !== $manager->getRawOriginal()
                || $this->policy($current) !== $policy) {
                throw new QrDineInException('invalid_pin', 401, 'Manager authorization changed. Verify again.');
            }
            $order = $this->order($current, $uuid, lock: true);
            // An uncertain HTTP reply can be retried with the same preview.
            // A terminal void is acknowledged, never reversed a second time.
            if ($order->status === Order::STATUS_VOID) {
                return ['order_uuid' => $uuid, 'status' => 'void', 'already_void' => true];
            }
            $this->eligible($order);
            if ($proof['expires'] <= now()->timestamp
                || ! hash_equals($proof['snapshot'], $this->fingerprint($this->snapshot($order)))) {
                throw $this->refusal('void_preview_changed', 'The bill changed. Review its latest items before cancelling.');
            }
            // Reuse closure/round rejection and accounting semantics, without
            // adding a general offline void or storing a manager PIN anywhere.
            $this->void->handle(new SyncEvent(['payload_json' => [
                'order_uuid' => $uuid,
                'reason' => 'Workspace manager #'.$manager->id.': '.trim($input['reason']),
            ]]), $current);

            return ['order_uuid' => $uuid, 'status' => 'void', 'already_void' => false];
        };
        if ($initial->order_type === 'dine_in') {
            // Device -> branch tables -> orders -> credentials -> primary and
            // joined seatings -> existing closure journal. No new event type.
            return $this->locks->locked($device, [
                'table_id' => (int) $initial->table_id,
                'seating_key' => (string) Str::uuid(), 'queued_offline' => false,
            ], 'staff_checkout', $cancel);
        }

        return DB::transaction(function () use ($device, $uuid, $cancel): array {
            $current = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if ($current === null || (int) $current->company_id !== (int) $device->company_id
                || (int) $current->branch_id !== (int) $device->branch_id) {
                throw $this->refusal('device_not_attended', 'Device assignment changed.');
            }
            $order = $this->order($current, $uuid, lock: true);
            if ($order->order_type !== 'quick' || $order->table_id !== null) {
                throw $this->refusal('void_preview_changed', 'The bill changed. Review it again.');
            }

            return $cancel($current);
        }, 5);
    }

    private function order(Device $device, string $uuid, bool $lock = false): Order
    {
        $query = Order::query()->where('uuid', $uuid)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->where(function ($query): void {
                $query->where('source', Order::SOURCE_QR_WEB)->orWhere(fn ($staff) => $staff
                    ->whereIn('source', ['main_pos', 'handheld'])->where('order_type', 'dine_in')
                    ->whereNotNull('table_id')->whereNotNull('table_session_id'));
            })->where(fn ($q) => $q->where(fn ($quick) => $quick->where('order_type', 'quick')->whereNull('table_id'))
            ->orWhere(fn ($table) => $table->where('order_type', 'dine_in')->whereNotNull('table_id')->whereNotNull('table_session_id')));
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($order === null) {
            throw new QrDineInException('order_not_found', 404, 'The shared bill was not found in this branch.');
        }

        return $order;
    }

    private function eligible(Order $order): void
    {
        if (! in_array($order->status, [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT], true)
            || $order->closed_at !== null) {
            throw $this->refusal('void_bill_not_unpaid', 'Only an unpaid bill can be cancelled here.');
        }
        // Even a declined/cancelled or expired claim remains evidence here.
        // This endpoint never clears a charge, releases a claim or recovers 004.
        foreach (['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas',
            'charge_claimed_at', 'charge_deadline_at', 'charge_outcome', 'transferred_to_device_id',
            'transferred_from_device_id', 'transferred_at'] as $field) {
            if ($order->$field !== null) {
                throw $this->refusal('qr_charge_recovery_required', 'Payment or transfer evidence requires separate review. Do not cancel or take another payment.');
            }
        }
        foreach (['pos_payments', 'pos_loyalty_transactions', 'pos_sale_commissions', 'pos_roundup_donations'] as $ledger) {
            if (DB::table($ledger)->where('order_id', $order->id)->exists()) {
                throw $this->refusal('qr_charge_recovery_required', 'This bill has accounting evidence requiring separate review.');
            }
        }
        if ($order->order_type === 'dine_in') {
            $seat = TableSession::query()->whereKey($order->table_session_id)->where('order_id', $order->id)
                ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('table_id', $order->table_id)->whereNull('merged_into_id')
                ->whereIn('status', TableSession::LIVE_STATUSES)->first();
            if ($seat === null) {
                throw $this->refusal('void_preview_changed', 'The seating changed. Review the current table bill.');
            }
        }
    }

    private function snapshot(Order $order): array
    {
        return ['bill' => $this->frozen->snapshot($order), 'seatings' => TableSession::query()
            ->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($seat) => $seat->getRawOriginal())->all()];
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function attended(Device $device): void
    {
        if ($device->trashed() || $device->status !== 'active' || ! $device->isAssigned()
            || ! in_array($device->device_type, ['fixed_pos', 'handheld'], true)) {
            throw $this->refusal('device_not_attended', 'An active assigned till or handheld is required.');
        }
    }

    private function policy(Device $device): mixed
    {
        return DB::table('pos_company_settings')->where('company_id', $device->company_id)
            ->where('key', 'manager_approval_positions')->value('value');
    }

    private function refusal(string $code, string $message): QrDineInException
    {
        return new QrDineInException($code, 409, $message);
    }
}
