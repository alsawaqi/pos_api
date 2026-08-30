<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Support\Facades\DB;

/** Frees a table only after every QR dine-in order is safely terminal. */
final class ClearDineInQrTableAction
{
    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

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

            $unpaid = Order::query()
                ->where('table_id', $tableId)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'dine_in')
                ->whereIn('status', [
                    Order::STATUS_OPEN,
                    Order::STATUS_HELD,
                    Order::STATUS_AWAITING_PAYMENT,
                ])
                ->lockForUpdate()
                ->get();
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
            if ($sessionIds !== []) {
                QrOrderRound::query()
                    ->whereIn('qr_session_id', $sessionIds)
                    ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                    ->update([
                        'status' => QrOrderRound::STATUS_REJECTED,
                        'resolved_at' => $now,
                        'resolved_by_device_id' => $device->id,
                        'updated_at' => $now,
                    ]);
                QrSession::query()->whereIn('id', $sessionIds)->update([
                    'status' => QrSession::STATUS_CLOSED,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return ['table_id' => (int) $table->id, 'status' => 'cleared'];
        });
    }
}
