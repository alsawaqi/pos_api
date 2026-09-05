<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use RuntimeException;

/** Order-rooted closure also works after the opening station was hard-deleted. */
final class CloseTableSessionForOrderAction
{
    public function handle(Order $lockedOrder, CarbonInterface $at, string $reason, ?int $deviceId): bool
    {
        if ($lockedOrder->table_session_id === null) {
            return false;
        }

        $seating = TableSession::query()
            ->whereKey((int) $lockedOrder->table_session_id)
            ->where('company_id', (int) $lockedOrder->company_id)
            ->where('branch_id', (int) $lockedOrder->branch_id)
            ->where('table_id', (int) $lockedOrder->table_id)
            ->lockForUpdate()
            ->first();

        if ($seating === null) {
            throw new RuntimeException('The order seating is missing or does not match its parent.');
        }

        return TableSession::query()
            ->whereKey($seating->getKey())
            ->where('company_id', (int) $lockedOrder->company_id)
            ->where('branch_id', (int) $lockedOrder->branch_id)
            ->where('table_id', (int) $lockedOrder->table_id)
            ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
            ->update([
                'status' => TableSession::STATUS_CLOSED,
                'closed_at' => $at,
                'closed_by_device_id' => $deviceId,
                'close_reason' => $reason,
                'updated_at' => $at,
            ]) > 0;
    }
}
