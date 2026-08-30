<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Return the authenticated public QR session and its newest order, if any. */
class QrStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->attributes->get('qr_session');

        if (! $session instanceof QrSession) {
            return QrApiResponse::failure(
                'qr_session_not_found',
                'QR session was not found.',
                404,
            );
        }

        $order = $session->orders()->latest('id')->first();

        $data = [
            'session_uuid' => $session->uuid,
            'status' => $session->status,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'order' => $order instanceof Order ? [
                'uuid' => $order->uuid,
                'status' => $order->status,
                'receipt_number' => $order->receipt_number,
                'subtotal_baisas' => Money::toBaisas($order->subtotal),
                'discount_total_baisas' => Money::toBaisas($order->discount_total),
                'tax_total_baisas' => Money::toBaisas($order->tax_total),
                'grand_total_baisas' => Money::toBaisas($order->grand_total),
            ] : null,
        ];

        // Quick is deliberately returned through the exact shipped shape.
        if (! $session->isDineIn()) {
            return QrApiResponse::success($data, [
                'money_unit' => 'baisas',
            ]);
        }

        $rounds = QrOrderRound::query()
            ->where('qr_session_id', $session->id)
            ->orderBy('round_no')
            ->get();
        $roundAllowed = $session->status === QrSession::STATUS_ACTIVE
            && ($order === null || $order->status === Order::STATUS_OPEN);
        $finishAllowed = $roundAllowed
            && $order !== null
            && $rounds->contains(
                static fn (QrOrderRound $round): bool => $round->status === QrOrderRound::STATUS_ACCEPTED,
            );
        $finishRefusal = null;
        if (! $finishAllowed) {
            if ($order === null || ! $rounds->contains(
                static fn (QrOrderRound $round): bool => $round->status === QrOrderRound::STATUS_ACCEPTED,
            )) {
                $finishRefusal = 'qr_dine_in_order_required';
            } elseif ($session->status !== QrSession::STATUS_ACTIVE) {
                $finishRefusal = 'qr_finish_session_not_active';
            } else {
                $finishRefusal = 'qr_finish_order_not_open';
            }
        }

        $data['dine_in'] = [
            'table_id' => (int) $session->table_id,
            'rounds' => $rounds->map(static fn (QrOrderRound $round): array => [
                'id' => (int) $round->id,
                'round_no' => (int) $round->round_no,
                'status' => (string) $round->status,
                'priced_lines' => $round->priced_lines,
                'subtotal_baisas' => (int) $round->subtotal_baisas,
                'tax_baisas' => (int) $round->tax_baisas,
                'total_baisas' => (int) $round->total_baisas,
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'resolved_at' => $round->resolved_at?->toIso8601String(),
            ])->values()->all(),
            'running_total_baisas' => $order instanceof Order
                ? Money::toBaisas($order->grand_total)
                : 0,
            'payment_state' => $this->paymentState($order),
            'round_submission' => [
                'allowed' => $roundAllowed,
                'refusal_code' => $roundAllowed
                    ? null
                    : ($session->status !== QrSession::STATUS_ACTIVE
                        ? 'qr_round_session_not_active'
                        : 'qr_round_order_not_open'),
            ],
            'finish_and_pay' => [
                'allowed' => $finishAllowed,
                'refusal_code' => $finishRefusal,
            ],
        ];

        return QrApiResponse::success($data, [
            'money_unit' => 'baisas',
        ]);
    }

    private function paymentState(?Order $order): ?string
    {
        if ($order === null || $order->status === Order::STATUS_OPEN) {
            return null;
        }
        if ($order->status === Order::STATUS_HELD) {
            return 'awaiting_counter';
        }
        if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
            $claimed = Order::query()->whereKey($order->id)->withLiveClaim()->exists();
            if ($claimed) {
                $attendedHolder = Device::query()
                    ->whereKey((int) $order->charge_device_id)
                    ->where('company_id', (int) $order->company_id)
                    ->where('branch_id', (int) $order->branch_id)
                    ->whereIn('device_type', QrChargeRecoveryGuard::ATTENDED_DEVICE_TYPES)
                    ->exists();

                return $attendedHolder ? 'awaiting_counter' : 'station_ready';
            }

            $safeToClaim = Order::query()->whereKey($order->id)->withoutLiveClaim()->exists();

            return $safeToClaim ? 'awaiting_station' : 'recovery_required';
        }
        if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
            return 'terminal';
        }

        return null;
    }
}
