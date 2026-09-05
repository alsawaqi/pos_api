<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Frees a physical table only after every covering dine-in bill is terminal. */
final class ClearDineInQrTableAction
{
    public function __construct(
        private readonly QrChargeRecoveryGuard $recovery,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return array{table_id: int, status: string} */
    public function handle(Device $device, int $tableId): array
    {
        if (! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may clear a table.',
            );
        }

        // Keep this outside the refusal transaction. An unpaid table is
        // expected to return a classified 409; placing the lazy flip inside
        // that transaction would roll it back and leave the recovery wedge
        // intact. Tenant + table scoping makes this safe even when clear is
        // refused after the touch.
        $now = now();
        QrSession::query()
            ->where('table_id', $tableId)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => QrSession::STATUS_EXPIRED,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

        return DB::transaction(function () use ($device, $tableId): array {
            $table = Table::query()
                ->withTrashed()
                ->select('pos_tables.*')
                ->join('pos_floors', 'pos_floors.id', '=', 'pos_tables.floor_id')
                ->where('pos_tables.id', $tableId)
                ->where('pos_tables.company_id', (int) $device->company_id)
                ->where('pos_floors.company_id', (int) $device->company_id)
                ->where('pos_floors.branch_id', (int) $device->branch_id)
                ->lockForUpdate()
                ->first();
            if ($table === null) {
                throw new QrDineInException('qr_table_not_found', 404, 'The table was not found.');
            }

            $orders = Order::query()
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('order_type', 'dine_in')
                ->where(function (Builder $covering) use ($tableId): void {
                    $covering->where('table_id', $tableId)->orWhereIn('id', DB::table('pos_order_tables')
                        ->select('order_id')->where('table_id', $tableId));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $unpaid = $orders->whereIn('status', [
                Order::STATUS_OPEN,
                Order::STATUS_HELD,
                Order::STATUS_AWAITING_PAYMENT,
                Order::STATUS_KITCHEN,
            ]);
            foreach ($unpaid as $order) {
                if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                    if (Order::query()->whereKey($order->id)->withLiveClaim(now())->exists()) {
                        throw new QrDineInException(
                            'qr_table_charge_live',
                            409,
                            'A live charge claim prevents clearing this table.',
                        );
                    }
                    if ($this->recovery->isAmbiguousCharge($order, now())) {
                        throw new QrDineInException(
                            'qr_charge_recovery_required',
                            409,
                            'This table has ambiguous charge evidence requiring attended recovery.',
                        );
                    }
                    throw new QrDineInException(
                        'qr_table_payment_pending',
                        409,
                        'This table still has an unpaid payment request.',
                    );
                }

                throw new QrDineInException(
                    'qr_table_unpaid_order',
                    409,
                    'This table still has an unpaid order.',
                );
            }

            $now = now();
            $sessionIds = QrSession::query()
                ->where('table_id', $tableId)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('status', '!=', QrSession::STATUS_CLOSED)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($sessionIds !== [] || $orders->isNotEmpty()) {
                QrOrderRound::query()
                    ->where(function (Builder $pending) use ($sessionIds, $orders): void {
                        $pending->whereIn('qr_session_id', $sessionIds)->orWhereIn('order_id', $orders->modelKeys());
                    })
                    ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                    ->update([
                        'status' => QrOrderRound::STATUS_REJECTED,
                        'resolved_at' => $now,
                        'resolved_by_device_id' => $device->id,
                        'confirm_payload' => null,
                        'updated_at' => $now,
                    ]);
            }
            if ($sessionIds !== []) {
                QrSession::query()->whereIn('id', $sessionIds)->update([
                    'status' => QrSession::STATUS_CLOSED,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $seatings = TableSession::query()
                ->where('table_id', $tableId)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($seatings as $seating) {
                TableSession::query()
                    ->whereKey($seating->id)
                    ->where('company_id', (int) $device->company_id)
                    ->where('branch_id', (int) $device->branch_id)
                    ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                    ->update([
                        'status' => TableSession::STATUS_CLOSED,
                        'closed_at' => $now,
                        'closed_by_device_id' => $device->id,
                        'close_reason' => TableSession::CLOSE_CLEARED,
                    ]);
                $this->journal->handle($seating, 'closed', ['close_reason' => TableSession::CLOSE_CLEARED], (int) $device->id, $now);
            }

            return ['table_id' => (int) $table->id, 'status' => 'cleared'];
        });
    }
}
