<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Support\Money;

/** Explicit allow-list presenter: the private confirm payload can never escape. */
final class PresentDeviceQrRoundAction
{
    /** @return array<string, mixed> */
    public function handle(Order $order, QrSession $session, QrOrderRound $round): array
    {
        $tableLabel = $session->table()->withTrashed()->value('label');

        return [
            'round' => [
                'id' => (int) $round->id,
                'round_no' => (int) $round->round_no,
                'status' => (string) $round->status,
                'priced_lines' => $round->priced_lines,
                'subtotal_baisas' => (int) $round->subtotal_baisas,
                'tax_baisas' => (int) $round->tax_baisas,
                'total_baisas' => (int) $round->total_baisas,
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'resolved_at' => $round->resolved_at?->toIso8601String(),
            ],
            'table_label' => is_string($tableLabel) ? $tableLabel : null,
            'receipt_number' => $order->receipt_number,
            'order_uuid' => (string) $order->uuid,
            'order' => [
                'subtotal_baisas' => Money::toBaisas($order->subtotal),
                'discount_total_baisas' => Money::toBaisas($order->discount_total),
                'tax_total_baisas' => Money::toBaisas($order->tax_total),
                'grand_total_baisas' => Money::toBaisas($order->grand_total),
            ],
        ];
    }
}
