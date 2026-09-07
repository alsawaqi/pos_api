<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use Closure;
use Illuminate\Support\Facades\DB;

/** Applies one device operation under the canonical order -> session -> round locks. */
final class WithLockedDineInQrRoundAction
{
    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

    public function handle(Device $device, int $roundId, Closure $operation): mixed
    {
        if (! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may manage QR rounds.',
            );
        }

        // Resolve only the identity needed to acquire the canonical first lock.
        // The tenant-scoped row is fully re-read after every lock is held.
        $orderId = QrOrderRound::query()
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_qr_order_rounds.order_id')
            ->where('pos_qr_order_rounds.id', $roundId)
            ->where('pos_orders.company_id', (int) $device->company_id)
            ->where('pos_orders.branch_id', (int) $device->branch_id)
            ->where('pos_orders.source', Order::SOURCE_QR_WEB)
            ->where('pos_orders.order_type', 'dine_in')
            ->value('pos_qr_order_rounds.order_id');
        if ($orderId === null) {
            throw $this->notFound();
        }

        return DB::transaction(function () use ($device, $roundId, $orderId, $operation): mixed {
            $order = Order::query()
                ->whereKey((int) $orderId)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'dine_in')
                ->lockForUpdate()
                ->first();
            if ($order === null || $order->qr_session_id === null) {
                throw $this->notFound();
            }

            $session = QrSession::query()
                ->whereKey((int) $order->qr_session_id)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->lockForUpdate()
                ->first();
            if ($session === null) {
                throw $this->notFound();
            }

            $credentialIds = [(int) $session->id];
            $parentId = $session->handover_from_id;
            while ($parentId !== null && ! in_array((int) $parentId, $credentialIds, true)) {
                $parent = QrSession::query()->whereKey((int) $parentId)
                    ->where('company_id', (int) $order->company_id)->where('branch_id', (int) $order->branch_id)
                    ->where('table_id', (int) $order->table_id)
                    ->where('table_session_id', $order->table_session_id ?? 0)
                    ->whereNotNull('released_at')->whereIn('status', [QrSession::STATUS_CLOSED, QrSession::STATUS_EXPIRED])
                    ->lockForUpdate()->first();
                if ($parent === null || (int) $session->table_session_id !== (int) $order->table_session_id) {
                    break;
                }
                $credentialIds[] = (int) $parent->id;
                $parentId = $parent->handover_from_id;
            }

            $round = QrOrderRound::query()
                ->whereKey($roundId)
                ->where('order_id', $order->getKey())
                ->whereIn('qr_session_id', $credentialIds)
                ->lockForUpdate()
                ->first();
            if ($round === null) {
                throw $this->notFound();
            }

            return $operation($order, $session, $round);
        }, 5);
    }

    private function notFound(): QrDineInException
    {
        return new QrDineInException(
            'qr_round_not_found',
            404,
            'The QR round was not found.',
        );
    }
}
