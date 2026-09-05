<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** A pure projection: expired horizons do not themselves close a seating. */
final class ListTableBoardAction
{
    public const UNPAID_STATUSES = [
        Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_KITCHEN,
    ];

    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

    public function assertReader(Device $device): void
    {
        if (! $device->isPaymentStation() && ! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException('device_not_table_board_reader', 409, 'This device may not read tables.');
        }
        if ($device->trashed() || $device->status !== 'active' || ! $device->isAssigned()) {
            throw new QrDineInException('device_unassigned', 409, 'This device is not active and assigned.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function handle(Device $device): array
    {
        $this->assertReader($device);
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;
        $tables = Table::query()
            ->select('pos_tables.*')
            ->join('pos_floors', 'pos_floors.id', '=', 'pos_tables.floor_id')
            ->where('pos_tables.company_id', $companyId)
            ->where('pos_floors.company_id', $companyId)
            ->where('pos_floors.branch_id', $branchId)
            ->whereNull('pos_floors.deleted_at')
            ->orderBy('pos_floors.display_order')
            ->orderBy('pos_tables.display_order')
            ->orderBy('pos_tables.id')
            ->get();
        $tableIds = $tables->modelKeys();
        if ($tableIds === []) {
            return [];
        }

        $seatings = TableSession::query()
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('status', TableSession::LIVE_STATUSES)
            ->whereIn('table_id', $tableIds)->orderBy('id')->get();
        // A joined table remains operational even if the primary's physical
        // table/floor was retired. Resolve that exact live generation, without
        // making the deleted physical table visible or following an alias.
        $primaryIds = $seatings->pluck('merged_into_id')->filter()->unique()->diff($seatings->modelKeys())->values()->all();
        if ($primaryIds !== []) {
            $seatings = $seatings->merge(TableSession::query()
                ->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereIn('id', $primaryIds)->whereNull('merged_into_id')
                ->whereIn('status', TableSession::LIVE_STATUSES)->orderBy('id')->get());
        }
        $seatingsById = $seatings->keyBy('id');
        $seatingsByTable = $seatings->keyBy('table_id');
        $orderIds = $seatings->pluck('order_id')->filter()->unique()->values()->all();

        // Keep unpaid legacy/orphan bills visible without inventing a seating.
        // A joined table's bill can be named by the existing order-table pivot.
        $orders = Order::query()
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('order_type', 'dine_in')
            ->where(function (Builder $query) use ($orderIds, $tableIds): void {
                $query->whereIn('id', $orderIds)->orWhere(function (Builder $unpaid) use ($tableIds): void {
                    $unpaid->whereIn('status', self::UNPAID_STATUSES)
                        ->where(function (Builder $atTable) use ($tableIds): void {
                            $atTable->whereIn('table_id', $tableIds)
                                ->orWhereIn('id', DB::table('pos_order_tables')->select('order_id')->whereIn('table_id', $tableIds));
                        });
                });
            })->orderByDesc('id')->get();
        $ordersById = $orders->keyBy('id');
        $pivots = DB::table('pos_order_tables')->whereIn('order_id', $orders->modelKeys())
            ->whereIn('table_id', $tableIds)->orderBy('table_id')->get()->groupBy('table_id');
        $rounds = QrOrderRound::query()
            ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->where(function (Builder $query) use ($seatings, $orders): void {
                $query->whereIn('table_session_id', $seatings->modelKeys())
                    ->orWhere(function (Builder $legacy) use ($orders): void {
                        $legacy->whereNull('table_session_id')->whereIn('order_id', $orders->modelKeys());
                    });
            })->get();
        $liveClaims = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('id', $orders->modelKeys())->withLiveClaim()->pluck('id')->all();

        return $tables->map(function (Table $table) use (
            $seatingsByTable, $seatingsById, $seatings, $orders, $ordersById, $pivots, $rounds, $liveClaims,
        ): array {
            /** @var TableSession|null $seating */
            $seating = $seatingsByTable->get($table->id);
            // Joined rows stay individually addressable, but carry the primary
            // party's reference and money; aliases never enter the live query.
            $primary = $seating?->merged_into_id === null
                ? $seating : $seatingsById->get($seating->merged_into_id);
            $order = $primary === null ? null : $ordersById->get($primary->order_id);
            if ($seating === null) {
                $pivotOrderIds = $pivots->get($table->id, collect())->pluck('order_id')->all();
                $order = $orders->first(static fn (Order $candidate): bool => in_array($candidate->status, self::UNPAID_STATUSES, true)
                    && ((int) $candidate->table_id === (int) $table->id || in_array($candidate->id, $pivotOrderIds)));
            }
            $family = $primary === null ? collect() : $seatings->filter(static fn (TableSession $candidate): bool => (int) $candidate->id === (int) $primary->id || (int) $candidate->merged_into_id === (int) $primary->id);
            $familyIds = $family->pluck('id')->all();
            $pending = $rounds->filter(static fn (QrOrderRound $round): bool => in_array($round->table_session_id, $familyIds)
                || ($order !== null && (int) $round->order_id === (int) $order->id));
            $needsReviewCount = $pending->where('needs_review', true)->count();

            return [
                'table_id' => (int) $table->id,
                'table_label' => (string) $table->label,
                'table_status' => (string) $table->status,
                'floor_id' => (int) $table->floor_id,
                'seating' => $seating === null ? null : [
                    'uuid' => (string) $seating->uuid,
                    'status' => (string) $seating->status,
                    'origin' => (string) $seating->origin,
                    'temp_reference' => $primary?->temp_reference,
                    'opened_at' => $seating->opened_at->toIso8601String(),
                    'expires_at' => $seating->expires_at->toIso8601String(),
                    'needs_review' => $needsReviewCount > 0,
                    'needs_review_count' => $needsReviewCount,
                    'joined_table_ids' => $family->where('id', '!=', $primary?->id)->pluck('table_id')->map(static fn ($id): int => (int) $id)->values()->all(),
                    'pending_rounds' => $pending->map(static function (QrOrderRound $round): array {
                        $held = [];
                        foreach ($round->priced_lines ?? [] as $index => $line) {
                            if (isset($line['held_reason'])) {
                                $held[] = [
                                    'line_index' => (int) ($line['line_index'] ?? $index),
                                    'product_id' => (int) $line['product_id'],
                                    'addon_id' => $line['addon_id'] ?? null,
                                    'reason' => $line['held_reason'],
                                ];
                            }
                        }

                        return [
                            'round_id' => (int) $round->id,
                            'round_no' => (int) $round->round_no,
                            'round_status' => $round->status,
                            'total_baisas' => (int) $round->total_baisas,
                            'priced_lines' => $round->priced_lines,
                            'review_reasons' => array_merge($round->origin_table_session_id !== null ? ['merged'] : [], $held !== [] ? ['catalogue'] : []),
                            'held_lines' => $held,
                        ];
                    })->values()->all(),
                ],
                'bill' => $order === null ? null : [
                    'order_uuid' => (string) $order->uuid,
                    'status' => (string) $order->status,
                    'grand_total_baisas' => Money::toBaisas($order->grand_total),
                    'receipt_number' => $order->receipt_number,
                    'temp_reference' => $order->temp_reference,
                    'pending_rounds' => $pending->count(),
                    Order::STATUS_AWAITING_PAYMENT => $order->status === Order::STATUS_AWAITING_PAYMENT,
                    'charge_claim_live' => in_array($order->id, $liveClaims),
                ],
            ];
        })->all();
    }
}
