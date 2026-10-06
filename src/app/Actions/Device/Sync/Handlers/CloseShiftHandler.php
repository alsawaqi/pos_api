<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\SyncRefusal;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\Device;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\SyncEvent;
use App\Support\Money;
use App\Support\PaymentStaffSchema;
use App\Support\Staff\AuthorizationGate;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.5 — processes a `shift.close` sync event.
 *
 * Resolves the shift scoped to the device's company + branch, then computes
 * the drawer reconciliation (§10.8):
 *
 *   expected_cash = opening_cash + Σ(cash payment amount) for cash payments
 *                   captured on the shift during the window.
 *   variance      = closing_cash − expected_cash   (negative ⇒ short)
 *
 * `pos_payments.amount` is the NET amount a tender applies to the bill, so
 * change is ALREADY excluded and must not be subtracted again. That is
 * guaranteed by the wire contract, not by convention: PayOrderHandler
 * rejects any pay whose tender sum deviates from grand_total by more than
 * one baisa, so an over-tendered cash sale records the bill amount and
 * carries the cash handed back in `change_given` purely as an audit datum.
 * Subtracting it here understated expected_cash by exactly the change given
 * on every handheld cash sale (the machine never sends the field), reading
 * as a phantom drawer OVER — and masking real shortages of equal size.
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
 *     As an MC-003 safety net, identified staff on the opening device whose
 *     activity is not covered by any shared shift are attributed to this
 *     physical drawer instead of disappearing from every close.
 *   - LEGACY shift: pure per-device drawer semantics, unchanged.
 */
class CloseShiftHandler implements SyncEventHandler
{
    /** How many order uuids one close may list (a long shift on a busy till). */
    public const MAX_ORDER_UUIDS = 5000;

    public function __construct(private readonly AuthorizationGate $gate) {}

    /**
     * Orders whose money belongs to the drawer that SETTLED them (the
     * successful payment's device and time), not the order's own device or
     * staff: QR orders (opened by a station), and LAUNCH-P6 customer tablet
     * orders (opened by the tablet, paid at a till or handheld). Fix order 1
     * (F-3): a tablet order is one with a pos_tablet_orders row, never a
     * device-sent `source`.
     */
    private static function settledByPayment($query): void
    {
        $query->where(fn ($settled) => $settled->where('pos_orders.source', Order::SOURCE_QR_WEB)
            ->orWhereExists(fn ($tablet) => $tablet->selectRaw('1')->from('pos_tablet_orders as shift_tablet')
                ->whereColumn('shift_tablet.order_id', 'pos_orders.id')));
    }

    /** Every other order: attributed by its own device / staff (the legacy rule). */
    private static function notSettledByPayment($query): void
    {
        $query->where('pos_orders.source', '!=', Order::SOURCE_QR_WEB)
            ->whereNotExists(fn ($tablet) => $tablet->selectRaw('1')->from('pos_tablet_orders as shift_tablet')
                ->whereColumn('shift_tablet.order_id', 'pos_orders.id'));
    }

    /**
     * LAUNCH-P6 fix order 2 (F-9; P5 owner decision 4 "each cashier keeps
     * their own drawer") — which shift a QR / tablet payment belongs to:
     *
     *  1. the payer's (pos_payments.staff_id) shared shift covering the payment
     *     time at the order's branch — the lowest id if, exceptionally, two
     *     cover it;
     *  2. otherwise (no payer recorded, or the payer has no covering shared
     *     shift) the shift of the device that took the payment;
     *  3. otherwise none.
     *
     * Exactly one shift counts a payment. The callers bound the payment time
     * by the shift's own window. Without the payer column (pos_admin 120002 not
     * migrated) only rule 2 applies, as before.
     */
    private static function paymentBelongsToShift($query, Shift $shift, string $payment): void
    {
        $payerFirst = PaymentStaffSchema::ready();
        $query->where(function ($who) use ($shift, $payment, $payerFirst): void {
            if ($payerFirst && (bool) $shift->is_shared && $shift->staff_id !== null) {
                $who->where(fn ($mine) => $mine->where($payment.'.staff_id', (int) $shift->staff_id)
                    ->whereNotExists(fn ($earlier) => self::payerCoveringShift($earlier, $payment)
                        ->where('payer_shift.id', '<', (int) $shift->id)));
            }
            $who->orWhere(function ($device) use ($shift, $payment, $payerFirst): void {
                $device->where($payment.'.device_id', (int) $shift->device_id);
                if ($payerFirst) {
                    $device->whereNotExists(fn ($cover) => self::payerCoveringShift($cover, $payment));
                }
            });
        });
    }

    /** A shared shift of the payment's payer, at the order's branch, open at the payment time. */
    private static function payerCoveringShift($query, string $payment)
    {
        return $query->selectRaw('1')->from('pos_shifts as payer_shift')
            ->whereColumn('payer_shift.staff_id', $payment.'.staff_id')
            ->where('payer_shift.is_shared', true)
            ->whereColumn('payer_shift.company_id', 'pos_orders.company_id')
            ->whereColumn('payer_shift.branch_id', 'pos_orders.branch_id')
            ->whereColumn('payer_shift.opened_at', '<=', $payment.'.captured_at')
            ->where(fn ($end) => $end->whereNull('payer_shift.closed_at')
                ->orWhereColumn('payer_shift.closed_at', '>=', $payment.'.captured_at'));
    }

    /**
     * LAUNCH-P5 (A5, owner decisions 3 and 4). A P5 build (auth_v: 1) sends
     * closed_by_staff_id, order_uuids (the paid orders of this shift on this
     * device), an authorization block when closing ANOTHER cashier's drawer,
     * and a FIXED client_event_id per shift and re-open (UUID v5, URL
     * namespace, of "shift-close:{shift_uuid}:{reopen_count}"), so a repeated
     * close returns the original Z from the ledger while a close after a
     * portal re-open (reopen_count + 1) is a new event with a fresh Z.
     *
     *  - Another cashier's drawer: the closer needs shift.close_other or a
     *    verified approval, else the close fails with approval_required /
     *    approval_invalid.
     *  - A listed order that has not reached the server as processed and paid
     *    refuses the close with the retryable `unsynced_sales`
     *    { missing: [uuids] }. An order whose event from this device is in
     *    PERMANENT failure (needs_review, or failed for any reason other than
     *    a database fault) does not block: it sets needs_review and is named
     *    in the shift's note.
     *  - Device pay-outs (pos_expenses.paid_from_drawer) of this drawer lower
     *    expected cash (payouts_baisas, a "Pay-outs" line in the summary):
     *    those naming this shift (shift_id), else by the shift's staff inside
     *    the window.
     *  - closed_by_staff_id and close_device_id are stamped.
     *
     * An old build's close keeps today's behaviour.
     */
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $shiftUuid = $payload['shift_uuid'] ?? null;
        if (! is_string($shiftUuid)) {
            throw new RuntimeException('invalid shift.close payload: shift_uuid required');
        }

        // Fix order 1 L2 — the close holds its shift row from the start (the
        // dispatcher's transaction), so a late-cash or late pay-out write
        // waits for it and a cash sale in flight is counted by one side.
        $shift = self::shiftForClose($device, $shiftUuid)->first();
        if ($shift === null) {
            throw new RuntimeException('shift not found for close: '.$shiftUuid);
        }
        if ($shift->status === Shift::STATUS_CLOSED) {
            throw new RuntimeException('shift already closed: '.$shiftUuid);
        }

        $closedAt = isset($payload['closed_at']) ? Carbon::parse((string) $payload['closed_at']) : now();
        $closingBaisas = (int) ($payload['closing_cash_baisas'] ?? 0);
        $p5 = AuthorizationGate::isP5($payload, $device);

        $closedBy = isset($payload['closed_by_staff_id']) ? (int) $payload['closed_by_staff_id'] : null;
        TenantReferenceGuard::assertStaffInTenant($device, $closedBy, 'shift.close references a staff member outside the device tenant');
        $authorization = null;
        if ($p5 && $closedBy !== null && $shift->staff_id !== null && $closedBy !== (int) $shift->staff_id) {
            $outcome = $this->gate->evaluate($device, [
                'action' => 'shift.close_other', 'subject_type' => 'shift', 'subject_uuid' => $shiftUuid,
                'actor_staff_id' => $closedBy, 'staff_token' => $payload['staff_token'] ?? null,
                'client_event_id' => (string) $event->client_event_id,
                'at' => $event->client_timestamp ?? now(),
            ], AuthorizationGate::block($payload['authorization'] ?? null), true);
            if (! $outcome->authorized()) {
                throw new SyncRefusal($outcome->refusalCode(), 'Closing another cashier\'s drawer needs a manager approval.',
                    ['reason' => $outcome->reason]);
            }
            $authorization = ['action' => 'shift.close_other', 'result' => $outcome->result];
        }

        $review = [];
        if ($p5 && array_key_exists('order_uuids', $payload)) {
            [$missing, $review] = $this->unsyncedSales($device, $payload['order_uuids']);
            if ($missing !== []) {
                throw new SyncRefusal('unsynced_sales', count($missing).' paid sale(s) of this shift have not reached the server yet.',
                    ['missing' => $missing]);
            }
        }

        return DB::transaction(function () use ($shift, $closedAt, $closingBaisas, $closedBy, $device, $review, $authorization): array {
            $cash = Payment::query()
                ->join('pos_orders', 'pos_payments.order_id', '=', 'pos_orders.id')
                ->where('pos_payments.method', Payment::METHOD_CASH)
                ->where('pos_payments.status', Payment::STATUS_SUCCESS)
                ->where($this->orderBelongsToShift(
                    $shift,
                    'pos_payments.captured_at',
                    'pos_payments.device_id',
                ))
                ->whereBetween('pos_payments.captured_at', [$shift->opened_at, $closedAt])
                ->selectRaw('COALESCE(SUM(pos_payments.amount), 0) as amt')
                ->first();

            // amount is already net of change (see the class docblock).
            $netCashBaisas = Money::toBaisas($cash->amt ?? 0);
            $payoutsBaisas = $this->payoutsBaisas($shift, $closedAt);
            $expectedBaisas = Money::toBaisas($shift->opening_cash) + $netCashBaisas - $payoutsBaisas;
            $varianceBaisas = $closingBaisas - $expectedBaisas;

            $update = [
                'status' => Shift::STATUS_CLOSED,
                'closed_at' => $closedAt,
                'closing_cash' => Money::toOmr($closingBaisas),
                'expected_cash' => Money::toOmr($expectedBaisas),
                'variance' => Money::toOmr($varianceBaisas),
                'closed_by_staff_id' => $closedBy,
                'close_device_id' => (int) $device->getKey(),
                'payouts_baisas' => $payoutsBaisas,
                // Follow-up 1 — this close's expected cash counts every cash
                // payment of the window, so cash that arrived "late" after an
                // earlier close (before a portal re-open) is no longer late;
                // the same for pay-outs (fix order 1 F7).
                'late_sales_baisas' => 0,
                'late_payouts_baisas' => 0,
            ];
            if ($review !== []) {
                $update['needs_review'] = true;
                $update['note'] = self::appendNote($shift->note, 'Sales in permanent sync failure: '.implode(', ', $review));
            }
            $shift->update($update);

            $summary = $this->salesSummary($shift, $closedAt);
            $summary['payouts_baisas'] = $payoutsBaisas;

            return [
                'shift_id' => (int) $shift->id,
                'status' => 'closed',
                // Follow-up 1 — the re-open this Z belongs to.
                'reopen_count' => (int) ($shift->reopen_count ?? 0),
                'expected_cash_baisas' => $expectedBaisas,
                'variance_baisas' => $varianceBaisas,
                // Phase C6 — the printed shift-summary (Z-report) numbers,
                // server-authoritative, piggybacked on the close the device
                // already awaits. Optional fields: old clients ignore them.
                'summary' => $summary,
            ] + ($review === [] ? [] : ['needs_review' => true, 'review_order_uuids' => $review])
              + ($authorization === null ? [] : ['authorization' => $authorization]);
        });
    }

    /**
     * The shift a close names, in the device's company and branch, locked
     * for update (fix order 1 L2).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Shift>
     */
    public static function shiftForClose(Device $device, string $shiftUuid): \Illuminate\Database\Eloquent\Builder
    {
        return Shift::query()
            ->where('uuid', $shiftUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->lockForUpdate();
    }

    /**
     * Split the listed paid orders into those that have not reached the
     * server (block the close) and those whose event from this device is in
     * permanent failure (do not block; reviewed).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function unsyncedSales(Device $device, mixed $listed): array
    {
        if (! is_array($listed)) {
            throw new RuntimeException('invalid shift.close payload: order_uuids must be a list');
        }
        $uuids = array_values(array_unique(array_filter($listed, static fn ($u): bool => is_string($u) && Str::isUuid($u))));
        if (count($uuids) > self::MAX_ORDER_UUIDS) {
            throw new RuntimeException('invalid shift.close payload: too many order_uuids');
        }

        $reached = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach (Order::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->whereIn('uuid', $chunk)
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED,
                    Order::STATUS_PENDING_VERIFICATION, Order::STATUS_COMBINED])
                ->pluck('uuid') as $uuid) {
                $reached[(string) $uuid] = true;
            }
        }
        $notReached = array_values(array_filter($uuids, static fn (string $u): bool => ! isset($reached[$u])));
        if ($notReached === []) {
            return [[], []];
        }

        // This device's order events for the missing sales, by order uuid.
        $permanent = [];
        $events = SyncEvent::query()->where('device_id', $device->getKey())
            ->whereIn('event_type', ['order.create', 'order.pay', 'order.deliver'])
            ->whereIn('ack_status', [SyncEvent::STATUS_FAILED, SyncEvent::STATUS_NEEDS_REVIEW])
            ->where(function ($q) use ($notReached): void {
                $q->whereIn('payload_json->order->uuid', $notReached)->orWhereIn('payload_json->order_uuid', $notReached);
            })
            ->get(['event_type', 'payload_json', 'ack_status', 'result_json']);
        foreach ($events as $row) {
            $payload = (array) $row->payload_json;
            $uuid = (string) ($payload['order']['uuid'] ?? ($payload['order_uuid'] ?? ''));
            $result = (array) ($row->result_json ?? []);
            if ($row->ack_status === SyncEvent::STATUS_NEEDS_REVIEW || ($result['permanent'] ?? false) === true
                || ($result['error'] ?? null) !== SyncEventDispatcher::TRANSIENT_ERROR) {
                $permanent[$uuid] = true;
            }
        }

        $missing = [];
        $review = [];
        foreach ($notReached as $uuid) {
            if (isset($permanent[$uuid])) {
                $review[] = $uuid;
            } else {
                $missing[] = $uuid;
            }
        }

        return [$missing, $review];
    }

    /**
     * Device pay-outs (paid_from_drawer) of this drawer, in baisas: those
     * that name this shift (pos_expenses.shift_id, fix order 1 F6), plus —
     * for pay-outs that name no shift (old builds) — today's rule: logged by
     * the shift's staff member inside the shift window.
     */
    private function payoutsBaisas(Shift $shift, Carbon $closedAt): int
    {
        $payouts = fn () => DB::table('pos_expenses')
            ->where('company_id', $shift->company_id)
            ->where('branch_id', $shift->branch_id)
            ->where('paid_from_drawer', true);
        $named = Money::toBaisas($payouts()->where('shift_id', $shift->id)->sum('amount'));
        if ($shift->staff_id === null) {
            return $named;
        }

        return $named + Money::toBaisas($payouts()
            ->whereNull('shift_id')
            ->where('logged_by_pos_staff_id', $shift->staff_id)
            ->whereBetween('logged_at', [$shift->opened_at, $closedAt])
            ->sum('amount'));
    }

    /**
     * LAUNCH-P5 (A5) — a successful CASH payment that lands inside a CLOSED
     * shift's window (the sale reached the server after the close) adds to
     * that shift's late_sales_baisas and sets needs_review. The printed Z is
     * not changed. The same attribution as the close decides the shift; at
     * most one shift takes it.
     *
     * @param  list<int>  $paymentIds
     * @return array{shift_uuid: string, late_sales_baisas: int}|null
     */
    public function recordLateCash(Order $order, array $paymentIds): ?array
    {
        $payments = Payment::query()->whereIn('id', $paymentIds ?: [0])
            ->where('method', Payment::METHOD_CASH)->where('status', Payment::STATUS_SUCCESS)
            ->whereNotNull('captured_at')->get();
        $late = null;
        foreach ($payments as $payment) {
            // Fix order 1 L2 — lock every shift whose window holds the payment,
            // open ones included: a close in flight holds its row (it locks it
            // first), so this waits for it and then sees it closed; a close
            // that starts later waits for this sale and counts it.
            $shifts = Shift::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('opened_at', '<=', $payment->captured_at)
                ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $payment->captured_at))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($shifts as $shift) {
                if ($shift->status !== Shift::STATUS_CLOSED || $shift->closed_at === null || $shift->closed_at->lt($payment->captured_at)) {
                    continue;
                }
                $claims = Payment::query()
                    ->join('pos_orders', 'pos_payments.order_id', '=', 'pos_orders.id')
                    ->where('pos_payments.id', $payment->id)
                    ->where($this->orderBelongsToShift($shift, 'pos_payments.captured_at', 'pos_payments.device_id'))
                    ->exists();
                if (! $claims) {
                    continue;
                }
                // In SQL, so two devices' late cash never overwrite each other.
                $baisas = Money::toBaisas($payment->amount);
                DB::table('pos_shifts')->where('id', $shift->id)->update([
                    'late_sales_baisas' => DB::raw('late_sales_baisas + '.$baisas),
                    'needs_review' => true,
                    'note' => self::appendNoteSql('Late cash sale '.$order->uuid.' ('.Money::toOmr($baisas).')'),
                    'updated_at' => now(),
                ]);
                $late = ['shift_uuid' => (string) $shift->uuid,
                    'late_sales_baisas' => (int) DB::table('pos_shifts')->where('id', $shift->id)->value('late_sales_baisas')];
                break;
            }
        }

        return $late;
    }

    /**
     * Fix order 1 F7 (review M5) — a DRAWER pay-out (paid_from_drawer) whose
     * shift is already closed (it reached the server after the close) adds to
     * that shift's late_payouts_baisas, sets needs_review and writes a note
     * line; the printed Z is not changed. The shift is the one the pay-out
     * names (shift_id), else today's rule: the logger's shift whose window
     * holds logged_at. The corrected expected cash = expected + late sales -
     * late pay-outs (the portal shows it).
     *
     * @return array{shift_uuid: string, late_payouts_baisas: int}|null
     */
    public function recordLatePayout(Expense $expense): ?array
    {
        if (! $expense->paid_from_drawer) {
            return null;
        }
        $query = Shift::query()->where('company_id', $expense->company_id)->where('branch_id', $expense->branch_id);
        if ($expense->shift_id !== null) {
            $query->whereKey((int) $expense->shift_id);
        } elseif ($expense->logged_by_pos_staff_id !== null && $expense->logged_at !== null) {
            // Every status: an in-flight close holds this row; wait for it.
            $query->where('staff_id', $expense->logged_by_pos_staff_id)->where('opened_at', '<=', $expense->logged_at)
                ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $expense->logged_at));
        } else {
            return null;
        }
        $shift = $query->orderBy('id')->lockForUpdate()->first();
        if ($shift === null || $shift->status !== Shift::STATUS_CLOSED) {
            return null;
        }
        $baisas = Money::toBaisas($expense->amount);
        DB::table('pos_shifts')->where('id', $shift->id)->update([
            'late_payouts_baisas' => DB::raw('late_payouts_baisas + '.$baisas),
            'needs_review' => true,
            'note' => self::appendNoteSql('Late pay-out '.$expense->uuid.' ('.Money::toOmr($baisas).')'),
            'updated_at' => now(),
        ]);

        return ['shift_uuid' => (string) $shift->uuid,
            'late_payouts_baisas' => (int) DB::table('pos_shifts')->where('id', $shift->id)->value('late_payouts_baisas')];
    }

    private static function appendNote(?string $note, string $line): string
    {
        return $note === null || trim($note) === '' ? $line : $note.' | '.$line;
    }

    /** Append a note line in SQL, so concurrent appends never lose one. */
    private static function appendNoteSql(string $line): Expression
    {
        $quoted = DB::getPdo()->quote($line);

        return DB::raw("CASE WHEN note IS NULL OR TRIM(note) = '' THEN {$quoted} ELSE note || ' | ' || {$quoted} END");
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
     * A final safety leg prevents a sale from disappearing when an identified
     * cashier rings on this drawer without holding a shared shift. It applies
     * only when no shared shift for that cashier covers the activity time, so
     * the normal staff leg of another shift remains authoritative and the sale
     * can land in exactly one close. DB-001's explicit shift_id supersedes this
     * temporal fallback.
     *
     * QR settlements are the exception to opener/staff attribution. When the
     * caller supplies a payment-device column, a QR order belongs exclusively
     * to the shift whose device took that payment; its opening station and
     * null staff must never pull it into another drawer.
     *
     * @return \Closure(Builder): void
     */
    private function orderBelongsToShift(
        Shift $shift,
        string $activityAtColumn = 'pos_orders.opened_at',
        ?string $paymentDeviceColumn = null,
    ): \Closure {
        return function ($q) use ($shift, $activityAtColumn, $paymentDeviceColumn): void {
            $q->where('pos_orders.company_id', $shift->company_id)
                ->where('pos_orders.branch_id', $shift->branch_id)
                ->where(function ($scope) use ($shift, $activityAtColumn, $paymentDeviceColumn): void {
                    if ($paymentDeviceColumn === null) {
                        $this->applyLegacyOrderIdentity($scope, $shift, $activityAtColumn);

                        return;
                    }

                    // A null shift device must never match nullable payment
                    // rows. Deleted-device shifts have no drawer identity.
                    if ($shift->device_id !== null) {
                        $scope->where(function ($qr) use ($shift, $paymentDeviceColumn): void {
                            self::settledByPayment($qr);
                            self::paymentBelongsToShift($qr, $shift, Str::before($paymentDeviceColumn, '.'));
                        });
                    } else {
                        $scope->whereRaw('0 = 1');
                    }

                    $scope->orWhere(function ($legacy) use ($shift, $activityAtColumn): void {
                        self::notSettledByPayment($legacy);
                        $legacy
                            ->where(function ($identity) use ($shift, $activityAtColumn): void {
                                $this->applyLegacyOrderIdentity(
                                    $identity,
                                    $shift,
                                    $activityAtColumn,
                                );
                            });
                    });
                });
        };
    }

    private function applyLegacyOrderIdentity(
        Builder $scope,
        Shift $shift,
        string $activityAtColumn,
    ): void {
        if ($shift->is_shared && $shift->staff_id !== null) {
            $scope->where('pos_orders.staff_id', $shift->staff_id)
                ->orWhere(function ($fallback) use ($shift): void {
                    $fallback
                        ->where('pos_orders.device_id', $shift->device_id)
                        ->whereNull('pos_orders.staff_id');
                });

            // Unlike SQL equality, Laravel turns a null value into IS NULL.
            // A deleted opening device must not make this shift claim every
            // unrelated null-device order.
            if ($shift->device_id !== null) {
                $scope->orWhere(function ($fallback) use ($shift, $activityAtColumn): void {
                    $fallback
                        ->where('pos_orders.device_id', $shift->device_id)
                        ->whereNotNull('pos_orders.staff_id')
                        ->where('pos_orders.staff_id', '!=', $shift->staff_id)
                        ->whereNotExists(function ($covering) use ($activityAtColumn): void {
                            $covering->selectRaw('1')
                                ->from('pos_shifts as covering_shift')
                                ->whereColumn('covering_shift.company_id', 'pos_orders.company_id')
                                ->whereColumn('covering_shift.branch_id', 'pos_orders.branch_id')
                                ->whereColumn('covering_shift.staff_id', 'pos_orders.staff_id')
                                ->where('covering_shift.is_shared', true)
                                ->whereColumn('covering_shift.opened_at', '<=', $activityAtColumn)
                                ->where(function ($end) use ($activityAtColumn): void {
                                    $end->whereNull('covering_shift.closed_at')
                                        ->orWhereColumn('covering_shift.closed_at', '>=', $activityAtColumn);
                                });
                        });
                });
            }

            return;
        }

        $scope->where('pos_orders.device_id', $shift->device_id);
    }

    /**
     * Select QR orders by the successful settlement row captured on this
     * shift's physical device and inside this shift's time window.
     *
     * @param  array{0: mixed, 1: mixed}  $window
     * @return \Closure(Builder): void
     */
    private function qrSettlementBelongsToShift(
        Shift $shift,
        array $window,
        ?string $linkedPaymentIdColumn = null,
    ): \Closure {
        return function ($orders) use ($shift, $window, $linkedPaymentIdColumn): void {
            $orders
                ->where('pos_orders.company_id', $shift->company_id)
                ->where('pos_orders.branch_id', $shift->branch_id)
                ->where(fn ($settled) => self::settledByPayment($settled));

            if ($shift->device_id === null) {
                $orders->whereRaw('0 = 1');

                return;
            }

            $orders->whereExists(function ($payments) use (
                $shift,
                $window,
                $linkedPaymentIdColumn,
            ): void {
                $payments->selectRaw('1')
                    ->from('pos_payments as qr_shift_payment')
                    ->whereColumn('qr_shift_payment.order_id', 'pos_orders.id')
                    ->where('qr_shift_payment.status', Payment::STATUS_SUCCESS)
                    ->where(fn ($who) => self::paymentBelongsToShift($who, $shift, 'qr_shift_payment'))
                    ->whereBetween('qr_shift_payment.captured_at', $window);

                if ($linkedPaymentIdColumn !== null) {
                    $payments->whereColumn('qr_shift_payment.id', $linkedPaymentIdColumn);
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

        $legacyOrders = DB::table('pos_orders')
            ->where(fn ($legacy) => self::notSettledByPayment($legacy))
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
        $qrOrders = DB::table('pos_orders')
            ->where($this->qrSettlementBelongsToShift($shift, $window))
            ->where('status', Order::STATUS_PAID)
            ->whereNull('delivery_confirmed_at')
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
            ->where($this->orderBelongsToShift(
                $shift,
                'pos_payments.captured_at',
                'pos_payments.device_id',
            ))
            ->whereBetween('pos_payments.captured_at', $window)
            ->groupBy('pos_payments.method')
            ->orderBy('pos_payments.method')
            ->selectRaw(
                // amount is already net of change (see the class docblock) —
                // subtracting change_given here understated the Z tender line.
                'pos_payments.method,'
                .' COALESCE(SUM(pos_payments.amount), 0) as amt,'
                .' COUNT(*) as cnt'
            )
            ->get();

        $legacyVoids = DB::table('pos_orders')
            ->where(fn ($legacy) => self::notSettledByPayment($legacy))
            ->where($this->orderBelongsToShift($shift))
            ->where('status', Order::STATUS_VOID)
            ->whereBetween('opened_at', $window)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as amt')
            ->first();
        // A never-settled QR void has no drawer identity and is deliberately
        // excluded. A paid-then-voided QR order follows its successful
        // settlement device/time, like every other QR money aggregate.
        $qrVoids = DB::table('pos_orders')
            ->where($this->qrSettlementBelongsToShift($shift, $window))
            ->where('status', Order::STATUS_VOID)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as amt')
            ->first();

        $legacyRoundUp = DB::table('pos_roundup_donations')
            ->join('pos_orders', 'pos_roundup_donations.order_id', '=', 'pos_orders.id')
            ->where(fn ($legacy) => self::notSettledByPayment($legacy))
            ->where($this->orderBelongsToShift($shift, 'pos_roundup_donations.created_at'))
            ->whereBetween('pos_roundup_donations.created_at', $window)
            ->sum('pos_roundup_donations.amount');
        // QR round-up is currently disabled. If historical residue exists,
        // attribute it through its linked successful settlement payment
        // rather than the opening station or donation timestamp.
        $qrRoundUp = DB::table('pos_roundup_donations')
            ->join('pos_orders', 'pos_roundup_donations.order_id', '=', 'pos_orders.id')
            ->where($this->qrSettlementBelongsToShift(
                $shift,
                $window,
                'pos_roundup_donations.payment_id',
            ))
            ->sum('pos_roundup_donations.amount');

        $expenses = DB::table('pos_expenses')
            ->where('branch_id', $shift->branch_id)
            ->whereBetween('logged_at', $window)
            ->sum('amount');

        return [
            'order_count' => (int) ($legacyOrders->cnt ?? 0) + (int) ($qrOrders->cnt ?? 0),
            'gross_sales_baisas' => Money::toBaisas($legacyOrders->sub ?? 0)
                + Money::toBaisas($qrOrders->sub ?? 0),
            'discount_total_baisas' => Money::toBaisas($legacyOrders->disc ?? 0)
                + Money::toBaisas($qrOrders->disc ?? 0),
            'comp_total_baisas' => Money::toBaisas($legacyOrders->comp ?? 0)
                + Money::toBaisas($qrOrders->comp ?? 0),
            'tax_total_baisas' => Money::toBaisas($legacyOrders->tax ?? 0)
                + Money::toBaisas($qrOrders->tax ?? 0),
            'grand_total_baisas' => Money::toBaisas($legacyOrders->grand ?? 0)
                + Money::toBaisas($qrOrders->grand ?? 0),
            'tenders' => $tenders->map(fn ($t): array => [
                'method' => (string) $t->method,
                'amount_baisas' => Money::toBaisas($t->amt),
                'count' => (int) $t->cnt,
            ])->values()->all(),
            'void_count' => (int) ($legacyVoids->cnt ?? 0) + (int) ($qrVoids->cnt ?? 0),
            'void_total_baisas' => Money::toBaisas($legacyVoids->amt ?? 0)
                + Money::toBaisas($qrVoids->amt ?? 0),
            'round_up_baisas' => Money::toBaisas($legacyRoundUp)
                + Money::toBaisas($qrRoundUp),
            'branch_expenses_baisas' => Money::toBaisas($expenses),
        ];
    }
}
