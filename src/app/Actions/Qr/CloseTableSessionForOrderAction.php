<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use RuntimeException;

/** Order-rooted closure also works after the opening station was hard-deleted. */
final class CloseTableSessionForOrderAction
{
    public function __construct(private readonly AppendTableSessionEventAction $journal) {}

    public function handle(Order $lockedOrder, CarbonInterface $at, string $reason, ?int $deviceId): int
    {
        if ($lockedOrder->table_session_id === null) {
            return 0;
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

        $joined = TableSession::query()
            ->where('company_id', (int) $lockedOrder->company_id)
            ->where('branch_id', (int) $lockedOrder->branch_id)
            ->where('order_id', (int) $lockedOrder->id)
            ->where('merged_into_id', (int) $seating->id)
            ->whereKeyNot($seating->id)
            ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if (in_array($reason, [TableSession::CLOSE_PAID, TableSession::CLOSE_VOIDED], true)) {
            $pending = QrOrderRound::query()
                ->where('order_id', (int) $lockedOrder->id)
                ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($pending as $round) {
                $round->update([
                    'status' => QrOrderRound::STATUS_REJECTED,
                    'resolved_at' => $at,
                    'resolved_by_device_id' => $deviceId,
                    'confirm_payload' => null,
                ]);
                $this->journal->handle($seating, 'round_resolved', [
                    'round_id' => (int) $round->id,
                    'outcome' => 'rejected',
                    'reason' => 'bill_'.$reason,
                ], $deviceId, $at);
            }
        }

        $closed = [];
        foreach (collect([$seating])->concat($joined) as $row) {
            $changed = TableSession::query()
                ->whereKey($row->id)
                ->where('company_id', (int) $lockedOrder->company_id)
                ->where('branch_id', (int) $lockedOrder->branch_id)
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->update([
                    'status' => TableSession::STATUS_CLOSED,
                    'closed_at' => $at,
                    'closed_by_device_id' => $deviceId,
                    'close_reason' => $reason,
                    'updated_at' => $at,
                ]);
            if ($changed > 0) {
                $closed[] = $row;
            }
        }
        // A QR credential can be attached before its first customer round.
        // At that point order.qr_session_id is still null: close credentials
        // through this exact seating family, never through the physical table.
        QrSession::query()
            ->where('company_id', (int) $lockedOrder->company_id)
            ->where('branch_id', (int) $lockedOrder->branch_id)
            ->whereIn('table_session_id', collect([$seating])->concat($joined)->pluck('id')->all())
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->update([
                'status' => QrSession::STATUS_CLOSED,
                'closed_at' => $at,
                'updated_at' => $at,
            ]);

        foreach ($closed as $row) {
            $this->journal->handle($row, 'closed', [
                'order_uuid' => (string) $lockedOrder->uuid,
                'close_reason' => $reason,
            ], $deviceId, $at);
        }

        return count($closed);
    }
}
