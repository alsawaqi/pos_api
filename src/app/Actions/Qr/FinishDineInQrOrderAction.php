<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Freezes a running tab and selects its attended or station payment path. */
final class FinishDineInQrOrderAction
{
    /** @return array<string, mixed> */
    public function handle(int $sessionId, string $paymentChoice): array
    {
        $result = DB::transaction(function () use ($sessionId, $paymentChoice): array|QrDineInException {
            $session = QrSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null || ! $session->isDineIn()) {
                throw new QrDineInException(
                    'qr_dine_in_session_required',
                    409,
                    'A live dine-in QR session is required.',
                );
            }

            $order = Order::query()
                ->where('qr_session_id', $session->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $acceptedRounds = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->count();
            $now = now();
            QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
                ->update([
                    'status' => QrOrderRound::STATUS_REJECTED,
                    'resolved_at' => $now,
                    'resolved_by_device_id' => null,
                    'updated_at' => $now,
                ]);
            if ($order === null || $acceptedRounds === 0) {
                // Return the refusal so the transaction commits the required
                // pending-round rejection before the controller emits it.
                return new QrDineInException(
                    'qr_dine_in_order_required',
                    409,
                    'Send at least one accepted round before finishing the tab.',
                );
            }

            $targetStatus = $paymentChoice === 'station'
                ? Order::STATUS_AWAITING_PAYMENT
                : Order::STATUS_HELD;
            if ($session->status === QrSession::STATUS_ORDERED
                && $order->status === $targetStatus) {
                return $this->present($order);
            }
            if ($session->status !== QrSession::STATUS_ACTIVE) {
                throw new QrDineInException(
                    'qr_finish_session_not_active',
                    409,
                    'This dine-in session is not open for finish-and-pay.',
                );
            }
            if ($order->status !== Order::STATUS_OPEN) {
                throw new QrDineInException(
                    'qr_finish_order_not_open',
                    409,
                    'This dine-in order is not open.',
                );
            }

            $order->update(['status' => $targetStatus]);
            $session->update([
                'status' => QrSession::STATUS_ORDERED,
                'last_seen_at' => $now,
            ]);

            return $this->present($order->fresh());
        });

        if ($result instanceof QrDineInException) {
            throw $result;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function present(Order $order): array
    {
        return [
            'order_uuid' => (string) $order->uuid,
            'status' => (string) $order->status,
            'receipt_number' => $order->receipt_number,
            'grand_total_baisas' => Money::toBaisas($order->grand_total),
        ];
    }
}
