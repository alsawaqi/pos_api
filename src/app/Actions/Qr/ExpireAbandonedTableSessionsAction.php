<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Global, age-gated pruning; an unpaid bill always keeps its seating. */
final class ExpireAbandonedTableSessionsAction
{
    public function handle(CarbonInterface $at, ?int $tableId = null, ?int $companyId = null, ?int $branchId = null): int
    {
        return TableSession::query()
            ->when($tableId !== null, fn (Builder $query) => $query->where('table_id', $tableId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
            ->where('expires_at', '<=', $at)
            ->where(function (Builder $seatings): void {
                $seatings
                    ->whereNull('order_id')
                    ->orWhereHas('order', function (Builder $orders): void {
                        $orders
                            ->whereColumn('pos_orders.company_id', 'pos_table_sessions.company_id')
                            ->whereColumn('pos_orders.branch_id', 'pos_table_sessions.branch_id')
                            ->whereColumn('pos_orders.table_id', 'pos_table_sessions.table_id')
                            ->whereIn('status', [
                                Order::STATUS_PAID,
                                Order::STATUS_PENDING_VERIFICATION,
                                Order::STATUS_VOID,
                                Order::STATUS_REFUNDED,
                            ]);
                    });
            })
            ->whereDoesntHave('qrSessions', function (Builder $sessions): void {
                $sessions->whereIn('status', QrSession::EXPIRABLE_STATUSES);
            })
            ->update([
                'status' => TableSession::STATUS_EXPIRED,
                'closed_at' => $at,
                'closed_by_device_id' => null,
                'close_reason' => TableSession::CLOSE_EXPIRED,
                'updated_at' => $at,
            ]);
    }
}
