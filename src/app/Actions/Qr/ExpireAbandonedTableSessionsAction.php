<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Global, age-gated pruning; an unpaid bill always keeps its seating. */
final class ExpireAbandonedTableSessionsAction
{
    public function __construct(private readonly AppendTableSessionEventAction $journal) {}

    public function handle(CarbonInterface $at, ?int $tableId = null, ?int $companyId = null, ?int $branchId = null): int
    {
        $expired = 0;
        foreach ($this->eligible($at, $tableId, $companyId, $branchId)->lazyById() as $candidate) {
            $expired += DB::transaction(function () use ($candidate, $at): int {
                // Parents precede the seating. Recheck the full abandonment
                // predicate under these locks; a stale scan must not expire a
                // seating to which a concurrent customer attached an order.
                Table::query()->withTrashed()
                    ->whereKey((int) $candidate->table_id)
                    ->where('company_id', (int) $candidate->company_id)
                    ->lockForUpdate()->first();
                if ($candidate->order_id !== null) {
                    Order::query()->whereKey((int) $candidate->order_id)
                        ->where('company_id', (int) $candidate->company_id)
                        ->where('branch_id', (int) $candidate->branch_id)
                        ->lockForUpdate()->first();
                }
                QrSession::query()
                    ->where('table_session_id', (int) $candidate->id)
                    ->where('company_id', (int) $candidate->company_id)
                    ->where('branch_id', (int) $candidate->branch_id)
                    ->orderBy('id')->lockForUpdate()->get();
                $seating = $this->eligible($at, (int) $candidate->table_id, (int) $candidate->company_id, (int) $candidate->branch_id)
                    ->whereKey($candidate->id)->lockForUpdate()->first();
                if ($seating === null || $seating->order_id !== $candidate->order_id) {
                    return 0;
                }

                TableSession::query()->whereKey($seating->id)
                    ->where('company_id', (int) $seating->company_id)
                    ->where('branch_id', (int) $seating->branch_id)
                    ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                    ->update([
                        'status' => TableSession::STATUS_EXPIRED,
                        'closed_at' => $at,
                        'closed_by_device_id' => null,
                        'close_reason' => TableSession::CLOSE_EXPIRED,
                        'updated_at' => $at,
                    ]);
                $this->journal->handle($seating, 'expired', ['close_reason' => TableSession::CLOSE_EXPIRED], null, $at);

                return 1;
            }, 5);
        }

        return $expired;
    }

    /** @return Builder<TableSession> */
    private function eligible(CarbonInterface $at, ?int $tableId, ?int $companyId, ?int $branchId): Builder
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
            });
    }
}
