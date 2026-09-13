<?php

declare(strict_types=1);

namespace App\Actions\Payments;

final class ReversalReceiptProjection
{
    public static function from(object $order, object $payment): array
    {
        $bank = $payment->bank_response;
        $bank = is_string($bank) ? json_decode($bank, true) : $bank;
        $bank = is_array($bank) ? $bank : [];
        $receipt = $bank['receiptResponse'] ?? [];
        $receipt = is_string($receipt) ? json_decode($receipt, true) : $receipt;
        $receipt = is_array($receipt) ? $receipt : [];
        $card = $receipt['maskedCard'] ?? $receipt['cardNumber'] ?? $bank['maskedCard'] ?? $bank['cardNumber'] ?? null;
        $masked = is_string($card) && preg_match('/^[0-9]{0,6}[Xx*]+[0-9]{0,4}$/D', $card) === 1;

        return [
            'order_uuid' => $order->uuid,
            'order_reference' => $order->receipt_number ?: $order->temp_reference ?: $order->uuid,
            'original_receipt_number' => $order->receipt_number,
            'original_masked_card' => $masked ? $card : null,
            'original_auth_code' => $payment->softpos_auth_code,
        ];
    }
}
