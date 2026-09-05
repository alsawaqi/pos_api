<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\AllocateQrRoundAcceptedSequenceAction;
use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\Money;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Reviews server-frozen staff/merged/held rounds without a QR credential. */
final class ConfirmStaffRoundAction
{
    private const SNAPSHOT_CHANGED = 'review order appeared while acquiring credential locks';

    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly AppendQrPricedLinesAction $append,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid, int $roundId): array
    {
        return $this->locked($device, $uuid, $roundId, function (Device $device, TableSession $seating, Order $order, QrOrderRound $round): array {
            if ($round->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION) {
                return $this->present($seating, $order, $round, 'replayed');
            }
            if ($order->status !== Order::STATUS_OPEN || $seating->status !== TableSession::STATUS_OPEN) {
                return $this->present($seating, $order, $round, $this->isTerminal($order) ? 'bill_terminal' : 'bill_unpaid');
            }
            if (! is_array($round->confirm_payload)) {
                throw new QrDineInException('nothing_to_confirm', 409, 'Every line is held; reject this round and re-enter the requested items.');
            }

            $this->append->handleStored($order, $round->confirm_payload);
            $lines = $round->priced_lines ?? [];
            $droppedCount = 0;
            foreach ($lines as &$line) {
                if (isset($line['held_reason'])) {
                    $line['held_disposition'] = 'dropped_at_review';
                    $droppedCount++;
                }
            }
            unset($line);
            $round->update([
                'status' => QrOrderRound::STATUS_ACCEPTED,
                'priced_lines' => $lines,
                'resolved_at' => now(),
                'resolved_by_device_id' => $device->id,
                'confirm_payload' => null,
            ]);
            $this->totals->handle($order);
            $round->update(['accepted_seq' => $this->acceptedSequence->next()]);
            $this->journal->handle($seating, 'round_resolved', [
                'round_id' => (int) $round->id,
                'order_uuid' => $order->uuid,
                'outcome' => QrOrderRound::STATUS_ACCEPTED,
                'dropped_line_count' => $droppedCount,
            ], (int) $device->id);

            return $this->present($seating, $order->fresh(), $round->fresh(), 'accepted');
        });
    }

    /**
     * Shared review locking: device -> tables -> orders -> credentials ->
     * primary/joined seatings -> round -> accepted sequence -> journal.
     *
     * @param  Closure(Device, TableSession, Order, QrOrderRound): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function locked(Device $device, string $uuid, int $roundId, Closure $operation): array
    {
        if (! Str::isUuid($uuid)) {
            throw $this->notFound();
        }
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($device, $uuid, $roundId, $operation): array {
                    $currentDevice = Device::query()->whereKey($device->id)->lockForUpdate()->first();
                    if ($currentDevice === null || $currentDevice->status !== 'active' || ! $currentDevice->isAssigned()
                        || (int) $currentDevice->company_id !== (int) $device->company_id
                        || (int) $currentDevice->branch_id !== (int) $device->branch_id) {
                        throw new QrDineInException('device_unassigned', 409, 'The device is not active and assigned.');
                    }
                    if (! in_array($currentDevice->device_type, ['fixed_pos', 'handheld'], true)) {
                        throw new QrDineInException('device_not_attended', 409, 'An attended device is required.');
                    }
                    $companyId = (int) $currentDevice->company_id;
                    $branchId = (int) $currentDevice->branch_id;
                    Table::withTrashed()->where('company_id', $companyId)
                        ->whereIn('floor_id', DB::table('pos_floors')->select('id')->where('company_id', $companyId)->where('branch_id', $branchId))
                        ->orderBy('id')->lockForUpdate()->get();
                    $orderScope = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                        ->where('order_type', 'dine_in')->whereNotNull('table_id');
                    $orders = (clone $orderScope)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    QrSession::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                        ->whereNotNull('table_id')->orderBy('id')->lockForUpdate()->get();
                    if ((clone $orderScope)->pluck('id')->diff($orders->keys())->isNotEmpty()) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED);
                    }
                    $scope = TableSession::query()->where('company_id', $companyId)->where('branch_id', $branchId);
                    $primaryRows = (clone $scope)->whereNull('merged_into_id')->orderBy('id')->lockForUpdate()->get();
                    $otherRows = (clone $scope)->whereNotNull('merged_into_id')->orderBy('id')->lockForUpdate()->get();
                    $seatings = $primaryRows->concat($otherRows)->keyBy('id');
                    $row = $seatings->firstWhere('uuid', $uuid);
                    if ($row === null) {
                        throw $this->notFound();
                    }
                    $resolved = $this->resolver->resolve($seatings, (string) $row->client_request_id, $uuid);
                    $seating = $resolved['primary'];
                    // A closed joined row still names its historical primary.
                    if ($seating !== null && $seating->merged_into_id !== null) {
                        $seating = $seatings->get((int) $seating->merged_into_id);
                    }
                    $order = $seating === null ? null : $orders->get((int) $seating->order_id);
                    if ($seating === null || $seating->merged_into_id !== null || $order === null
                        || (int) $order->table_session_id !== (int) $seating->id) {
                        throw $this->notFound();
                    }
                    $round = QrOrderRound::query()->whereKey($roundId)->where('order_id', $order->id)
                        ->where('table_session_id', $seating->id)->whereNull('qr_session_id')
                        ->lockForUpdate()->first();
                    if ($round === null) {
                        throw $this->notFound();
                    }
                    $result = $operation($currentDevice, $seating, $order, $round);
                    $ids = array_map(static fn ($event): int => (int) $event->id, $this->journal->flush());
                    $result['event_id'] = $ids[0] ?? null;
                    if (count($ids) > 1) {
                        $result['event_ids'] = $ids;
                    }

                    return $result;
                }, 5);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== self::SNAPSHOT_CHANGED || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function present(TableSession $seating, Order $order, QrOrderRound $round, string $outcome): array
    {
        $held = [];
        $dropped = [];
        foreach ($round->priced_lines ?? [] as $index => $line) {
            if (isset($line['held_reason'])) {
                $held[] = [
                    'line_index' => (int) ($line['line_index'] ?? $index),
                    'product_id' => (int) $line['product_id'],
                    'addon_id' => $line['addon_id'] ?? null,
                    'reason' => $line['held_reason'],
                ];
                if (($line['held_disposition'] ?? null) === 'dropped_at_review') {
                    $dropped[] = $line;
                }
            }
        }

        return [
            'outcome' => $outcome,
            'table_session_uuid' => $seating->uuid,
            'order_uuid' => $order->uuid,
            'order_status' => $order->status,
            'temp_reference' => $seating->temp_reference,
            'grand_total_baisas' => Money::toBaisas($order->grand_total),
            'round_id' => (int) $round->id,
            'round_no' => (int) $round->round_no,
            'round_status' => $round->status,
            'total_baisas' => (int) $round->total_baisas,
            'accepted_seq' => $round->accepted_seq,
            'needs_review' => (bool) $round->needs_review,
            'origin_table_session_id' => $round->origin_table_session_id,
            'kitchen_printed_at' => $round->kitchen_printed_at === null ? null : Carbon::parse($round->kitchen_printed_at)->toIso8601String(),
            'print_pending' => (bool) $round->needs_review && $round->status === QrOrderRound::STATUS_ACCEPTED && $round->kitchen_printed_at === null,
            'review_reasons' => array_merge($round->origin_table_session_id !== null ? ['merged'] : [], $held !== [] ? ['catalogue'] : []),
            'held_lines' => $held,
            'dropped_lines' => $dropped,
            'priced_lines' => $round->priced_lines ?? [],
            'event_id' => null,
        ];
    }

    private function isTerminal(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_PENDING_VERIFICATION], true);
    }

    private function notFound(): QrDineInException
    {
        return new QrDineInException('table_round_not_found', 404, 'The round was not found on this seating in this branch.');
    }
}
