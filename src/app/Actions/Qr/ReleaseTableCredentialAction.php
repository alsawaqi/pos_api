<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

/** Attended explicit release only. Bill ownership changes later, at card bind. */
final class ReleaseTableCredentialAction
{
    public function __construct(private readonly QrChargeRecoveryGuard $guard) {}

    /** @return array<string, mixed> */
    public function handle(Device $authenticatedDevice, string $uuid): array
    {
        return DB::transaction(function () use ($authenticatedDevice, $uuid): array {
            $device = Device::withTrashed()->whereKey($authenticatedDevice->id)->lockForUpdate()->first();
            if ($device === null || $device->trashed() || $device->status !== 'active'
                || ! $device->isAssigned() || ! $this->guard->isAttendedDevice($device)) {
                throw new QrDineInException('device_not_attended', 409, 'Only an attended fixed POS or handheld device may release a table credential.');
            }
            $query = TableSession::query()->where('uuid', $uuid)
                ->where('company_id', (int) $device->company_id)->where('branch_id', (int) $device->branch_id);
            $snapshot = (clone $query)->first();
            if ($snapshot === null) {
                throw new QrDineInException('qr_table_not_found', 404, 'The table was not found.');
            }
            Table::query()->whereKey((int) $snapshot->table_id)->lockForUpdate()->firstOrFail();
            $bill = $snapshot->order_id === null ? null : Order::query()->whereKey((int) $snapshot->order_id)
                ->where('company_id', (int) $device->company_id)->where('branch_id', (int) $device->branch_id)
                ->lockForUpdate()->first();
            $credential = QrSession::query()->where('table_session_id', $snapshot->id)
                ->where('company_id', (int) $device->company_id)->where('branch_id', (int) $device->branch_id)
                ->whereIn('status', QrSession::EXPIRABLE_STATUSES)->latest('id')->lockForUpdate()->first();
            if ($credential === null && $bill?->qr_session_id !== null) {
                $credential = QrSession::query()->whereKey((int) $bill->qr_session_id)
                    ->where('table_session_id', $snapshot->id)->where('table_id', $snapshot->table_id)
                    ->where('company_id', (int) $device->company_id)->where('branch_id', (int) $device->branch_id)
                    ->where('status', QrSession::STATUS_EXPIRED)->whereNull('released_at')->lockForUpdate()->first();
            }
            $seating = (clone $query)->lockForUpdate()->firstOrFail();
            if ($seating->merged_into_id !== null) {
                throw new QrDineInException('qr_table_joined', 409, 'This table belongs to another table\'s seating.');
            }
            if ($seating->status !== TableSession::STATUS_OPEN
                || (int) $seating->order_id !== (int) $bill?->id
                || ($bill !== null && ($bill->status !== Order::STATUS_OPEN
                    || Order::query()->whereKey($bill->id)->withLiveClaim()->exists()))
                || $credential?->status === QrSession::STATUS_ORDERED) {
                throw new QrDineInException('qr_release_bill_frozen', 409, 'This bill cannot release its phone while it is being paid.');
            }
            if ($credential === null || $credential->released_at !== null) {
                return ['released' => 0];
            }
            $credential->update([
                'status' => $credential->status === QrSession::STATUS_EXPIRED ? QrSession::STATUS_EXPIRED : QrSession::STATUS_CLOSED,
                'closed_at' => now(), 'released_at' => now(),
            ]);

            return ['released' => 1, 'credential_uuid' => (string) $credential->uuid, 'bill_uuid' => $bill?->uuid];
        }, 5);
    }
}
