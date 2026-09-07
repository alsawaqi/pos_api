<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use RuntimeException;

/** Open-only D2: supersede a bill-less, credential-less seating at any age. */
final class SupersedeAbandonedTableSessionAction
{
    public function __construct(private readonly AppendTableSessionEventAction $journal) {}

    public function handle(
        Table $lockedTable,
        ?Device $openingDevice,
        CarbonInterface $at,
        ?int $companyId = null,
        ?int $branchId = null,
    ): ?TableSession {
        $companyId ??= $openingDevice === null ? null : (int) $openingDevice->company_id;
        $branchId ??= $openingDevice === null ? null : (int) $openingDevice->branch_id;
        if ($companyId === null || $branchId === null) {
            throw new RuntimeException('A device-less opening requires explicit company and branch.');
        }
        $tableId = (int) $lockedTable->getKey();

        if ((int) $lockedTable->company_id !== $companyId) {
            throw new RuntimeException('The opening table does not match the seating tenant.');
        }

        $seating = TableSession::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('table_id', $tableId)
            ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
            ->lockForUpdate()
            ->first();

        if ($seating === null) {
            return null;
        }

        // The caller already expired stale credentials under the table lock.
        // These reads acquire no parent lock after the new seating lock.
        if (QrSession::query()
            ->where('table_session_id', $seating->getKey())
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->exists()) {
            return $seating;
        }

        if ($seating->order_id !== null) {
            $order = Order::query()
                ->whereKey((int) $seating->order_id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('table_id', $tableId)
                ->first();

            if ($order === null) {
                throw new RuntimeException('The seating order is missing or does not match its parent.');
            }

            if (! in_array($order->status, [
                Order::STATUS_PAID,
                Order::STATUS_PENDING_VERIFICATION,
                Order::STATUS_VOID,
                Order::STATUS_REFUNDED,
            ], true)) {
                return $seating;
            }
        }

        $attributes = [
            'status' => TableSession::STATUS_EXPIRED,
            'closed_at' => $at,
            'closed_by_device_id' => $openingDevice?->getKey(),
            'close_reason' => TableSession::CLOSE_ABANDONED,
            'updated_at' => $at,
        ];
        TableSession::query()
            ->whereKey($seating->getKey())
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('table_id', $tableId)
            ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
            ->update($attributes);
        $seating->forceFill($attributes)->syncOriginal();
        $this->journal->handle($seating, 'expired', ['close_reason' => TableSession::CLOSE_ABANDONED], $openingDevice?->getKey(), $at);

        return $seating;
    }
}
