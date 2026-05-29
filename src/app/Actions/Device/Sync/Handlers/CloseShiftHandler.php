<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8.5 — processes a `shift.close` sync event.
 *
 * Resolves the shift scoped to the device's company + branch, then computes
 * the drawer reconciliation (§10.8):
 *
 *   expected_cash = opening_cash + Σ(cash tendered − change given) for cash
 *                   payments captured on THIS device during the shift window.
 *   variance      = closing_cash − expected_cash   (negative ⇒ short)
 *
 * Orders carry no shift_id, so the shift's cash is attributed temporally:
 * cash payments on the shift's device between opened_at and closed_at.
 */
class CloseShiftHandler implements SyncEventHandler
{
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $shiftUuid = $payload['shift_uuid'] ?? null;
        if (! is_string($shiftUuid)) {
            throw new RuntimeException('invalid shift.close payload: shift_uuid required');
        }

        $shift = Shift::query()
            ->where('uuid', $shiftUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();
        if ($shift === null) {
            throw new RuntimeException('shift not found for close: '.$shiftUuid);
        }
        if ($shift->status === Shift::STATUS_CLOSED) {
            throw new RuntimeException('shift already closed: '.$shiftUuid);
        }

        $closedAt = isset($payload['closed_at']) ? Carbon::parse((string) $payload['closed_at']) : now();
        $closingBaisas = (int) ($payload['closing_cash_baisas'] ?? 0);

        return DB::transaction(function () use ($shift, $closedAt, $closingBaisas): array {
            $cash = Payment::query()
                ->join('pos_orders', 'pos_payments.order_id', '=', 'pos_orders.id')
                ->where('pos_payments.method', Payment::METHOD_CASH)
                ->where('pos_payments.status', Payment::STATUS_SUCCESS)
                ->where('pos_orders.device_id', $shift->device_id)
                ->whereBetween('pos_payments.captured_at', [$shift->opened_at, $closedAt])
                ->selectRaw('COALESCE(SUM(pos_payments.amount), 0) as amt, COALESCE(SUM(pos_payments.change_given), 0) as chg')
                ->first();

            $netCashBaisas = Money::toBaisas($cash->amt ?? 0) - Money::toBaisas($cash->chg ?? 0);
            $expectedBaisas = Money::toBaisas($shift->opening_cash) + $netCashBaisas;
            $varianceBaisas = $closingBaisas - $expectedBaisas;

            $shift->update([
                'status' => Shift::STATUS_CLOSED,
                'closed_at' => $closedAt,
                'closing_cash' => Money::toOmr($closingBaisas),
                'expected_cash' => Money::toOmr($expectedBaisas),
                'variance' => Money::toOmr($varianceBaisas),
            ]);

            return [
                'shift_id' => (int) $shift->id,
                'status' => 'closed',
                'expected_cash_baisas' => $expectedBaisas,
                'variance_baisas' => $varianceBaisas,
            ];
        });
    }
}
