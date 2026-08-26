<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Models\Order;
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

        return QrApiResponse::success([
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
        ], [
            'money_unit' => 'baisas',
        ]);
    }
}
