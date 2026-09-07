<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Support\Str;
use RuntimeException;

/** Attach under the caller's order/session locks; the counter is locked last. */
final class EnsureTableSessionForQrSessionAction
{
    public function __construct(
        private readonly AllocateQrTempReferenceAction $tempReferences,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    public function handle(QrSession $lockedSession, ?Order $lockedOrder, ?Device $device): TableSession
    {
        if (! $lockedSession->isDineIn()) {
            throw new RuntimeException('A quick QR session cannot have a table seating.');
        }

        $companyId = (int) $lockedSession->company_id;
        $branchId = (int) $lockedSession->branch_id;
        $tableId = (int) $lockedSession->table_id;

        if ($lockedOrder !== null && (
            (int) $lockedOrder->company_id !== $companyId
            || (int) $lockedOrder->branch_id !== $branchId
            || (int) $lockedOrder->table_id !== $tableId
        )) {
            throw new RuntimeException('The QR order does not match its seating tenant or table.');
        }

        if ($device !== null && (
            (int) $device->company_id !== $companyId
            || (int) $device->branch_id !== $branchId
        )) {
            throw new RuntimeException('The device does not match the QR seating tenant.');
        }

        if ($lockedSession->table_session_id !== null) {
            $seating = TableSession::query()
                ->whereKey((int) $lockedSession->table_session_id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('table_id', $tableId)
                ->lockForUpdate()
                ->first();

            if ($seating === null || ($lockedOrder?->table_session_id !== null
                && (int) $lockedOrder->table_session_id !== (int) $seating->getKey())) {
                throw new RuntimeException('The QR seating is missing or does not match its parent.');
            }

            return $seating;
        }

        if ($lockedOrder?->table_session_id !== null) {
            throw new RuntimeException('The order is already attached to a different QR seating.');
        }

        $billing = $lockedOrder !== null && in_array($lockedOrder->status, [
            Order::STATUS_HELD,
            Order::STATUS_AWAITING_PAYMENT,
        ], true);

        $seating = TableSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'table_id' => $tableId,
            'status' => $billing ? TableSession::STATUS_BILLING : TableSession::STATUS_OPEN,
            'origin' => $lockedSession->origin === 'table_card'
                ? TableSession::ORIGIN_TABLE_CARD : TableSession::ORIGIN_STATION,
            'opened_by_device_id' => $lockedSession->device_id,
            'order_id' => $lockedOrder?->getKey(),
            'opened_at' => $lockedSession->created_at,
            'expires_at' => $lockedSession->expires_at,
            // A legacy order's NULL reference stays NULL; its receipt is its identity.
            'temp_reference' => $lockedOrder?->temp_reference,
            // No earlier billing transition exists for a legacy row: best evidence.
            'billing_at' => $billing ? $lockedOrder->updated_at : null,
        ]);

        if ($lockedOrder === null) {
            $reference = $this->tempReferences->handle($companyId, $branchId);
            TableSession::query()
                ->whereKey($seating->getKey())
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('table_id', $tableId)
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->whereNull('temp_reference')
                ->update(['temp_reference' => $reference]);
            $seating->temp_reference = $reference;
        }

        $lockedSession->update(['table_session_id' => $seating->getKey()]);
        if ($lockedOrder !== null) {
            $lockedOrder->update(['table_session_id' => $seating->getKey()]);
        }

        QrOrderRound::query()
            ->where('qr_session_id', $lockedSession->getKey())
            ->whereNull('table_session_id')
            ->toBase()
            ->update(['table_session_id' => $seating->getKey()]);

        $this->journal->handle($seating, 'opened', [
            'session_uuid' => (string) $lockedSession->uuid,
            'lazy_attachment' => true,
        ], $device?->getKey());

        return $seating;
    }
}
