<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Branch-scoped operational view, including unpaid orders orphaned by expiry. */
final class ListDineInQrTableBoardAction
{
    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

    /** @return list<array<string, mixed>> */
    public function handle(Device $device): array
    {
        if (! $device->isPaymentStation() && ! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException(
                'device_not_table_board_reader',
                409,
                'This device may not read the table board.',
            );
        }
        if ($device->status !== 'active' || ! $device->isAssigned()) {
            throw new QrDineInException(
                'device_unassigned',
                409,
                'This device is not active and assigned.',
            );
        }

        $tables = Table::query()
            ->withTrashed()
            ->select('pos_tables.*')
            ->join('pos_floors', 'pos_floors.id', '=', 'pos_tables.floor_id')
            ->where('pos_tables.company_id', (int) $device->company_id)
            ->where('pos_floors.company_id', (int) $device->company_id)
            ->where('pos_floors.branch_id', (int) $device->branch_id)
            ->orderBy('pos_tables.display_order')
            ->orderBy('pos_tables.id')
            ->get();
        $tableIds = $tables->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($tableIds === []) {
            return [];
        }

        // A dine-in browser may disappear after finish-and-pay without ever
        // touching a public route again. Staff reads are therefore also a
        // lazy-expiry boundary: make the same conditional, one-way transition
        // as ResolveQrSession before projecting the board. Scoping to the
        // authenticated branch's concrete table ids prevents a board reader
        // from sweeping unrelated sessions.
        $now = now();
        QrSession::query()
            ->whereIn('table_id', $tableIds)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => QrSession::STATUS_EXPIRED,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

        /** @var Collection<int, Collection<int, QrSession>> $sessions */
        $sessions = QrSession::query()
            ->whereIn('table_id', $tableIds)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->whereIn('status', [
                QrSession::STATUS_PENDING,
                QrSession::STATUS_ACTIVE,
                QrSession::STATUS_ORDERED,
                QrSession::STATUS_EXPIRED,
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('table_id');
        /** @var Collection<int, Collection<int, Order>> $orders */
        $orders = Order::query()
            ->whereIn('table_id', $tableIds)
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->where('source', Order::SOURCE_QR_WEB)
            ->where('order_type', 'dine_in')
            ->whereIn('status', [
                Order::STATUS_OPEN,
                Order::STATUS_HELD,
                Order::STATUS_AWAITING_PAYMENT,
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('table_id');

        $rows = [];
        foreach ($tables as $table) {
            /** @var Order|null $order */
            $order = $orders->get((int) $table->id, collect())->first();
            $tableSessions = $sessions->get((int) $table->id, collect());
            /** @var QrSession|null $session */
            $session = $order?->qr_session_id === null
                ? $tableSessions->first()
                : $tableSessions->firstWhere('id', (int) $order->qr_session_id);
            $session ??= $tableSessions->first();
            if ($session === null && $order === null) {
                continue;
            }

            $pendingRounds = $session === null
                ? collect()
                : QrOrderRound::query()
                    ->where('qr_session_id', $session->id)
                    ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                    ->orderBy('round_no')
                    ->get();
            $orphaned = $order !== null && (
                $session === null
                || $session->status === QrSession::STATUS_EXPIRED
                || $session->expires_at === null
                || $session->expires_at->lte(now())
            );

            $rows[] = [
                'table_id' => (int) $table->id,
                'table_label' => (string) $table->label,
                'table_status' => (string) $table->status,
                'table_deleted' => $table->trashed(),
                'session_uuid' => $session?->uuid,
                'session_status' => $session?->status,
                'expires_at' => $session?->expires_at?->toIso8601String(),
                'orphaned' => $orphaned,
                'order' => $order === null ? null : [
                    'uuid' => (string) $order->uuid,
                    'status' => (string) $order->status,
                    'receipt_number' => $order->receipt_number,
                    'accepted_total_baisas' => Money::toBaisas($order->grand_total),
                ],
                'pending_rounds' => $pendingRounds->map(
                    static fn (QrOrderRound $round): array => [
                        'id' => (int) $round->id,
                        'round_no' => (int) $round->round_no,
                        'subtotal_baisas' => (int) $round->subtotal_baisas,
                        'tax_baisas' => (int) $round->tax_baisas,
                        'total_baisas' => (int) $round->total_baisas,
                        'submitted_at' => $round->submitted_at?->toIso8601String(),
                    ],
                )->values()->all(),
            ];
        }

        return $rows;
    }
}
