<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\PaymentReversal;
use Illuminate\Support\Facades\DB;

final class ReversalContract
{
    public static function present(PaymentReversal $reversal): array
    {
        $order = DB::table('pos_orders')->where('id', $reversal->order_id)->first();

        return [
            'reversal_uuid' => $reversal->uuid, 'kind' => $reversal->kind, 'status' => $reversal->status,
            'amount_baisas' => $reversal->amount_baisas, 'currency' => $reversal->currency_code,
            'softpos' => [
                'provider' => $reversal->softpos_provider, 'package' => $reversal->softpos_package,
                'needs_session' => $reversal->void_needs_session_id,
                'needs_transaction_id' => $reversal->kind === 'refund' && $reversal->refund_needs_transaction_id,
            ],
            'original_transaction_id' => $reversal->original_softpos_transaction_id,
            'description' => 'Mithqal '.strtoupper($reversal->kind).' '.($order->receipt_number ?: $order->temp_reference ?: $order->uuid),
            'attempted_at' => $reversal->attempted_at?->toIso8601String(),
            'completed_at' => $reversal->completed_at?->toIso8601String(),
            'response_code' => $reversal->response_code,
        ];
    }

    public static function fingerprint(array $payload): string
    {
        unset($payload['manager_pin']);

        return hash('sha256', json_encode(self::sort($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = self::sort($child);
            }
        }

        return $value;
    }
}
