<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** One physical table, one canonical bill, all its rounds; never a cart. */
final class ReadTableDetailAction
{
    public function __construct(
        private readonly TableReadSnapshot $snapshot,
        private readonly PresentQrPendingOrderAction $present,
        private readonly QrChargeRecoveryGuard $recovery,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, int $tableId): array
    {
        return $this->inspect($device, $tableId, static fn (Device $current, array $detail): array => $detail);
    }

    /** Extend a read within the SAME tenant-checked, consistent snapshot. */
    public function inspect(Device $device, int $tableId, Closure $read): array
    {
        $this->assertAttended($device);

        return $this->snapshot->handle($this->reader($device, $tableId, $read));
    }

    /** Caller owns a consistent read snapshot or the resolver's entire locked graph. */
    public function inspectLocked(Device $device, int $tableId, Closure $read): array
    {
        $this->assertAttended($device);

        return ($this->reader($device, $tableId, $read))();
    }

    private function reader(Device $device, int $tableId, Closure $read): Closure
    {
        return function () use ($device, $tableId, $read): array {
            $current = Device::withTrashed()->find($device->id);
            if ($current === null || (int) $current->company_id !== (int) $device->company_id
                || (int) $current->branch_id !== (int) $device->branch_id) {
                throw new QrDineInException('device_not_attended', 409, 'This device is no longer assigned to this branch.');
            }
            $this->assertAttended($current);
            $companyId = (int) $current->company_id;
            $branchId = (int) $current->branch_id;
            $table = Table::withTrashed()->select('pos_tables.*', 'pos_floors.deleted_at as floor_deleted_at')
                ->join('pos_floors', 'pos_floors.id', '=', 'pos_tables.floor_id')
                ->where('pos_tables.id', $tableId)->where('pos_tables.company_id', $companyId)
                ->where('pos_floors.company_id', $companyId)->where('pos_floors.branch_id', $branchId)->first();
            if ($table === null) {
                throw new QrDineInException('table_not_found', 404, 'The table was not found in this branch.');
            }

            $seatScope = TableSession::query()->where('company_id', $companyId)->where('branch_id', $branchId);
            $live = (clone $seatScope)->where('table_id', $tableId)
                ->whereIn('status', TableSession::LIVE_STATUSES)->orderBy('id')->get();
            if ($live->count() > 1) {
                throw $this->conflict();
            }
            $seat = $live->first();
            $primary = $seat;
            if ($seat?->merged_into_id !== null) {
                $primary = (clone $seatScope)->whereKey($seat->merged_into_id)->whereNull('merged_into_id')
                    ->whereIn('status', TableSession::LIVE_STATUSES)->first();
                if ($primary === null || (int) $seat->order_id !== (int) $primary->order_id) {
                    throw $this->conflict();
                }
            }

            $orderScope = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->where('order_type', 'dine_in')->whereNotNull('table_id');
            $linked = $primary?->order_id === null ? null : (clone $orderScope)->find($primary->order_id);
            if ($primary?->order_id !== null && ($linked === null
                || (int) $linked->table_id !== (int) $primary->table_id
                || (int) $linked->table_session_id !== (int) $primary->id)) {
                throw $this->conflict();
            }
            $unpaid = (clone $orderScope)->whereIn('status', ListTableBoardAction::UNPAID_STATUSES)
                ->where(function (Builder $query) use ($tableId, $linked): void {
                    $query->where('table_id', $tableId)
                        ->orWhereIn('id', DB::table('pos_order_tables')->select('order_id')->where('table_id', $tableId));
                    if ($linked !== null) {
                        $query->orWhere('id', $linked->id);
                    }
                })->orderBy('id')->get();
            if ($unpaid->count() > 1 || ($seat !== null && $unpaid->isNotEmpty()
                && (int) $unpaid->first()->id !== (int) $linked?->id)) {
                // Never pick a "latest" bill when two generations cover a table.
                throw $this->conflict();
            }
            $order = $linked ?? $unpaid->first();
            if ($primary === null && $order?->table_session_id !== null) {
                $primary = (clone $seatScope)->whereKey($order->table_session_id)->whereNull('merged_into_id')
                    ->where('order_id', $order->id)->where('table_id', $order->table_id)->first();
                if ($primary === null) {
                    throw $this->conflict();
                }
            }
            $family = $primary === null ? collect() : (clone $seatScope)
                ->where(function (Builder $query) use ($primary): void {
                    $query->whereKey($primary->id)->orWhere(function (Builder $joined) use ($primary): void {
                        $joined->where('merged_into_id', $primary->id)
                            ->whereIn('status', TableSession::LIVE_STATUSES)->where('order_id', $primary->order_id);
                    });
                })->orderBy('table_id')->orderBy('id')->get();
            $familyIds = $family->pluck('id')->all();
            $credentialScope = QrSession::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereIn('table_id', array_unique([$tableId, ...$family->pluck('table_id')->all(), $order?->table_id]));
            $credentials = (clone $credentialScope)->where(function (Builder $query) use ($familyIds, $order, $tableId): void {
                $query->whereIn('table_session_id', $familyIds);
                if ($order?->qr_session_id !== null) {
                    $query->orWhere('id', $order->qr_session_id);
                } elseif ($familyIds === [] && $order === null) {
                    $query->orWhere(fn (Builder $legacy) => $legacy->where('table_id', $tableId)
                        ->whereIn('status', QrSession::EXPIRABLE_STATUSES)->whereNull('released_at'));
                }
            })->orderBy('id')->get();
            $credential = $order?->qr_session_id !== null
                ? $credentials->firstWhere('id', $order->qr_session_id)
                : $credentials->whereNull('released_at')->last();

            $rounds = QrOrderRound::query()->where(function (Builder $query) use ($order, $familyIds, $credentials): void {
                if ($order !== null) {
                    $query->where('order_id', $order->id);
                } else {
                    $query->whereRaw('1 = 0');
                }
                // Historical pending rows may predate bill assignment. Include
                // only bill-less rows on this exact family/credential chain.
                $query->orWhere(fn (Builder $pending) => $pending->whereNull('order_id')
                    ->where(fn (Builder $scoped) => $scoped->whereIn('table_session_id', $familyIds)
                        ->orWhereIn('qr_session_id', $credentials->modelKeys())));
            })->orderBy('round_no')->orderBy('id')->get();
            $order?->load(['items' => fn ($q) => $q->orderBy('id'), 'items.addons' => fn ($q) => $q->orderBy('id'),
                'comps' => fn ($q) => $q->orderBy('id')]);
            $bill = $order === null ? null : Arr::except($this->present->mapOrder($order), [
                'customer_id', 'plate_number', 'delivery',
            ]) + ['charge' => $this->present->charge($order, now())]
                + TableBillAdjustmentView::detailFields($order);
            if ($order !== null && StaffTableCheckoutAction::shape($order) && $primary !== null) {
                $bill['checkout_policy'] = StaffTableCheckoutAction::POLICY;
            }

            $detail = [
                'table' => ['id' => (int) $table->id, 'label' => (string) $table->label,
                    'floor_id' => (int) $table->floor_id, 'status' => (string) $table->status,
                    'archived' => $table->trashed() || $table->floor_deleted_at !== null],
                'occupied' => $seat !== null || $unpaid->isNotEmpty()
                    || ($credential !== null && in_array($credential->status, QrSession::EXPIRABLE_STATUSES, true)
                        && $credential->released_at === null && $credential->expires_at?->gt(now())),
                'orphaned' => $seat === null && $unpaid->isNotEmpty(),
                'seating' => $primary === null ? null : [
                    'uuid' => (string) $primary->uuid, 'table_id' => (int) $primary->table_id,
                    'selected_table_session_uuid' => $seat?->uuid,
                    'status' => (string) $primary->status, 'origin' => (string) $primary->origin,
                    'temp_reference' => $primary->temp_reference,
                    'opened_at' => $primary->opened_at?->toIso8601String(),
                    'expires_at' => $primary->expires_at?->toIso8601String(),
                    'billing_at' => $primary->billing_at?->toIso8601String(),
                    'joined_table_ids' => $family->where('id', '!=', $primary->id)->pluck('table_id')
                        ->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
                ],
                'credential' => $credential === null ? null : [
                    'status' => $credential->released_at !== null ? 'released' : $credential->status,
                    'origin' => $credential->origin,
                    'expired' => $credential->status === QrSession::STATUS_EXPIRED || $credential->expires_at?->lte(now()) === true,
                ],
                'bill' => $bill,
                'rounds' => $rounds->map(fn (QrOrderRound $round): array => $this->round($round))->all(),
            ];

            return $read($current, $detail);
        };
    }

    private function assertAttended(Device $device): void
    {
        if (! $this->recovery->isAttendedDevice($device) || $device->trashed()
            || $device->status !== 'active' || ! $device->isAssigned()) {
            throw new QrDineInException('device_not_attended', 409, 'Only an active assigned till or handheld may read table details.');
        }
    }

    private function conflict(): QrDineInException
    {
        return new QrDineInException('table_bill_conflict', 409, 'This table has conflicting bill links. A manager must reconcile them before ordering or payment.');
    }

    /** @return array<string, mixed> */
    private function round(QrOrderRound $round): array
    {
        return [
            'id' => (int) $round->id, 'round_no' => (int) $round->round_no, 'status' => (string) $round->status,
            'entered_by' => $round->qr_session_id === null ? 'staff' : 'customer',
            'client_request_id' => $round->qr_session_id === null ? $round->client_request_id : null,
            'needs_review' => (bool) $round->needs_review,
            'priced_lines' => array_map(static function (array $line): array {
                $safe = Arr::only($line, ['line_index', 'product_id', 'product_name', 'product_name_ar', 'qty', 'notes',
                    'base_price_baisas', 'unit_price_baisas', 'line_discount_baisas', 'line_total_baisas',
                    'order_item_id', 'cancelled_qty', 'cancelled_discount_baisas', 'held_reason', 'held_disposition',
                    'addon_id', 'addon_ids', 'requested']);
                foreach (['addons' => ['add_on_id', 'name', 'name_ar', 'price_delta_baisas'],
                    'cancellations' => ['qty', 'discount_baisas', 'at']] as $key => $fields) {
                    if (isset($line[$key])) {
                        $safe[$key] = array_map(static fn (array $entry): array => Arr::only($entry, $fields), $line[$key]);
                    }
                }

                return $safe;
            }, $round->priced_lines ?? []),
            'subtotal_baisas' => (int) $round->subtotal_baisas, 'tax_baisas' => (int) $round->tax_baisas,
            'total_baisas' => (int) $round->total_baisas,
            'submitted_at' => $round->submitted_at?->toIso8601String(),
            'resolved_at' => $round->resolved_at?->toIso8601String(),
            'kitchen_printed_at' => $round->getRawOriginal('kitchen_printed_at'),
        ];
    }
}
