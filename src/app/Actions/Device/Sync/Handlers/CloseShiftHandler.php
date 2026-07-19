<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Order;
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
 *                   payments captured on the shift during the window.
 *   variance      = closing_cash − expected_cash   (negative ⇒ short)
 *
 * Orders carry no shift_id, so attribution is temporal + identity, inside
 * the shift's own company + branch:
 *   - SHARED shift (HH-2, is_shared): orders rung BY THE SHIFT'S STAFF on
 *     any terminal, plus staff-less orders on the opening device. This is
 *     what makes one shift a day span pos_machine + pos_handheld — the
 *     person opens once, sells on both, and the close reconciles the
 *     combined cash they hold. Keying the shared leg on staff (not device)
 *     also keeps two coexisting shifts disjoint: another cashier's sales on
 *     this shift's device belong to THEIR shift, never double-counted here.
 *   - LEGACY shift: pure per-device drawer semantics, unchanged.
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
                ->where($this->orderBelongsToShift($shift))
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
                // Phase C6 — the printed shift-summary (Z-report) numbers,
                // server-authoritative, piggybacked on the close the device
                // already awaits. Optional fields: old clients ignore them.
                'summary' => $this->salesSummary($shift, $closedAt),
            ];
        });
    }

    /**
     * HH-2 — the attribution predicate shared by every close-time query
     * (see the class doc for the semantics). Everything is wrapped in one
     * ->where(...) group so the OR never leaks into the surrounding query's
     * other conditions, and the group is TENANT-SCOPED explicitly: the
     * staff leg matches pos_orders.staff_id, which is client-asserted, so
     * without the company/branch bound a foreign or buggy device could
     * corrupt this shift's reconciliation.
     *
     * @return \Closure(\Illuminate\Contracts\Database\Query\Builder): void
     */
    private function orderBelongsToShift(Shift $shift): \Closure
    {
        return function ($q) use ($shift): void {
            $q->where('pos_orders.company_id', $shift->company_id)
                ->where('pos_orders.branch_id', $shift->branch_id)
                ->where(function ($scope) use ($shift): void {
                    if ($shift->is_shared && $shift->staff_id !== null) {
                        $scope->where('pos_orders.staff_id', $shift->staff_id)
                            ->orWhere(function ($fallback) use ($shift): void {
                                $fallback
                                    ->where('pos_orders.device_id', $shift->device_id)
                                    ->whereNull('pos_orders.staff_id');
                            });
                    } else {
                        $scope->where('pos_orders.device_id', $shift->device_id);
                    }
                });
        };
    }

    /**
     * Phase C6 — the shift's sales summary (blueprint Phase 9 #88 "Daily
     * sales summary"; Additions §1.2 Shift Report fields), computed inside
     * the close transaction over the same window + attribution the
     * expected-cash math uses: orders on the shift's device or by its staff,
     * temporally between opened_at and closed_at. Money = integer baisas on
     * the wire.
     *
     * Expenses are BRANCH-scoped (pos_expenses carries no device_id) and
     * informational only — they do not enter the drawer math, matching
     * the merchant ShiftReportAction.
     *
     * @return array<string, mixed>
     */
    private function salesSummary(Shift $shift, Carbon $closedAt): array
    {
        $window = [$shift->opened_at, $closedAt];

        $orders = DB::table('pos_orders')
            ->where($this->orderBelongsToShift($shift))
            ->where('status', Order::STATUS_PAID)
            // P-G7 — confirmed delivery-provider orders never put money in
            // the drawer, and confirmation RE-DATES opened_at to whenever the
            // merchant reconciled the statement — which can fall inside any
            // later shift's window on this till. The Z is a drawer document:
            // exclude them so its totals keep reconciling with the tenders.
            ->whereNull('delivery_confirmed_at')
            ->whereBetween('opened_at', $window)
            ->selectRaw(
                'COUNT(*) as cnt,'
                .' COALESCE(SUM(subtotal), 0) as sub,'
                .' COALESCE(SUM(discount_total), 0) as disc,'
                .' COALESCE(SUM(comp_total), 0) as comp,'
                .' COALESCE(SUM(tax_total), 0) as tax,'
                .' COALESCE(SUM(grand_total), 0) as grand'
            )
            ->first();

        $tenders = Payment::query()
            ->join('pos_orders', 'pos_payments.order_id', '=', 'pos_orders.id')
            ->where('pos_payments.status', Payment::STATUS_SUCCESS)
            ->where($this->orderBelongsToShift($shift))
            ->whereBetween('pos_payments.captured_at', $window)
            ->groupBy('pos_payments.method')
            ->orderBy('pos_payments.method')
            ->selectRaw(
                'pos_payments.method,'
                .' COALESCE(SUM(pos_payments.amount - COALESCE(pos_payments.change_given, 0)), 0) as amt,'
                .' COUNT(*) as cnt'
            )
            ->get();

        $voids = DB::table('pos_orders')
            ->where($this->orderBelongsToShift($shift))
            ->where('status', Order::STATUS_VOID)
            ->whereBetween('opened_at', $window)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as amt')
            ->first();

        // Round-ups ride their order (order_id NOT NULL), so they follow the
        // same device-or-staff attribution as every other money line.
        $roundUp = DB::table('pos_roundup_donations')
            ->join('pos_orders', 'pos_roundup_donations.order_id', '=', 'pos_orders.id')
            ->where($this->orderBelongsToShift($shift))
            ->whereBetween('pos_roundup_donations.created_at', $window)
            ->sum('pos_roundup_donations.amount');

        $expenses = DB::table('pos_expenses')
            ->where('branch_id', $shift->branch_id)
            ->whereBetween('logged_at', $window)
            ->sum('amount');

        return [
            'order_count' => (int) ($orders->cnt ?? 0),
            'gross_sales_baisas' => Money::toBaisas($orders->sub ?? 0),
            'discount_total_baisas' => Money::toBaisas($orders->disc ?? 0),
            'comp_total_baisas' => Money::toBaisas($orders->comp ?? 0),
            'tax_total_baisas' => Money::toBaisas($orders->tax ?? 0),
            'grand_total_baisas' => Money::toBaisas($orders->grand ?? 0),
            'tenders' => $tenders->map(fn ($t): array => [
                'method' => (string) $t->method,
                'amount_baisas' => Money::toBaisas($t->amt),
                'count' => (int) $t->cnt,
            ])->values()->all(),
            'void_count' => (int) ($voids->cnt ?? 0),
            'void_total_baisas' => Money::toBaisas($voids->amt ?? 0),
            'round_up_baisas' => Money::toBaisas($roundUp),
            'branch_expenses_baisas' => Money::toBaisas($expenses),
        ];
    }
}
