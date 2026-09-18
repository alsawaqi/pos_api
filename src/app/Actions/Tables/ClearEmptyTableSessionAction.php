<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

/** Close only the selected empty generation; never void a bill or discard rounds. */
final class ClearEmptyTableSessionAction
{
    public function handle(Device $device, int $tableId, string $uuid): array
    {
        app(ListTableBoardAction::class)->assertReader($device);

        return DB::transaction(function () use ($device, $tableId, $uuid): array {
            $table = Table::query()->whereKey($tableId)
                ->where('company_id', $device->company_id)
                ->whereIn('floor_id', DB::table('pos_floors')->select('id')
                    ->where('branch_id', $device->branch_id)->where('company_id', $device->company_id)->whereNull('deleted_at'))
                ->lockForUpdate()->first();
            if ($table === null) {
                throw new QrDineInException('qr_table_not_found', 404, 'The table was not found.');
            }
            // Serialize with the first customer round before inspecting the seating.
            $credentials = QrSession::query()->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('table_id', $tableId)
                ->orderBy('id')->lockForUpdate()->get();
            $seating = TableSession::query()->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('table_id', $tableId)
                ->where('uuid', $uuid)->lockForUpdate()->first();
            if ($seating === null || ! in_array($seating->status, TableSession::LIVE_STATUSES, true)) {
                throw new QrDineInException('table_session_changed', 409, 'The session changed. Refresh the table.');
            }
            $familyExists = $seating->merged_into_id !== null || TableSession::query()
                ->where('merged_into_id', $seating->id)->whereIn('status', TableSession::LIVE_STATUSES)->exists();
            $sessionIds = $credentials->where('table_session_id', $seating->id)->modelKeys();
            $unattachedLive = $credentials->contains(fn ($s) => in_array($s->status, QrSession::EXPIRABLE_STATUSES, true)
                && (int) $s->table_session_id !== (int) $seating->id);
            $hasOrders = Order::query()->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where(function ($q) use ($seating, $tableId, $sessionIds): void {
                    $q->where('table_session_id', $seating->id)->orWhere(function ($q) use ($tableId, $sessionIds): void {
                        $q->whereIn('status', ListTableBoardAction::UNPAID_STATUSES)
                            ->where(function ($q) use ($tableId, $sessionIds): void {
                                $q->where('table_id', $tableId)
                                    ->orWhereIn('id', DB::table('pos_order_tables')->select('order_id')->where('table_id', $tableId))
                                    ->orWhereIn('qr_session_id', $sessionIds);
                            });
                    });
                })->exists();
            $hasRounds = QrOrderRound::query()->where(function ($q) use ($seating, $sessionIds): void {
                $q->where('table_session_id', $seating->id)->orWhereIn('qr_session_id', $sessionIds);
            })->exists();
            if ($familyExists || $unattachedLive || $seating->order_id !== null || $hasOrders || $hasRounds) {
                throw new QrDineInException('table_session_not_empty', 409, 'This session is no longer empty. Refresh and review it.');
            }
            $now = now();
            QrSession::query()->whereIn('id', $sessionIds)->where('status', '!=', QrSession::STATUS_CLOSED)
                ->update(['status' => QrSession::STATUS_CLOSED, 'closed_at' => $now, 'updated_at' => $now]);
            $seating->update(['status' => TableSession::STATUS_CLOSED, 'closed_at' => $now,
                'closed_by_device_id' => $device->id, 'close_reason' => TableSession::CLOSE_CLEARED]);
            app(AppendTableSessionEventAction::class)->handle($seating, 'closed',
                ['close_reason' => TableSession::CLOSE_CLEARED, 'empty_session' => true], (int) $device->id, $now);

            return ['table_id' => $tableId, 'seating_uuid' => $uuid, 'status' => 'cleared'];
        }, 5);
    }
}
