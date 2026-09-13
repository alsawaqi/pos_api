<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Device\ResolveDeviceSoftPos;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Support\Money;

final class PaymentReversalOptionsAction
{
    public function __construct(
        private readonly ReservePaymentReversalAction $reserve,
        private readonly ResolveDeviceSoftPos $profiles,
    ) {}

    public function handle(Device $device, string $uuid): array
    {
        $this->reserve->assertDevice($device);
        $order = Order::query()->where('uuid', $uuid)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->first();
        if ($order === null) {
            throw new ReversalException('order_not_found', 404);
        }
        $remaining = $this->reserve->remainingLines($order);
        $lines = [];
        foreach ($remaining as $id => $row) {
            $lines[] = ['order_item_id' => $id, 'remaining_qty' => Money::toOmr($row['remaining'])];
        }
        $profile = $this->profiles->handle($device);
        $open = PaymentReversal::query()->where('order_id', $order->id)->whereIn('status', ['pending', 'uncertain']);
        $hasOpenReversal = (clone $open)->exists();
        $hasOpenVoid = (clone $open)->where('kind', 'void')->exists();
        $payments = [];
        foreach (Payment::query()->where('order_id', $order->id)->where('method', 'card')->where('direction', 'sale')->orderBy('id')->get() as $payment) {
            $reason = null;
            try {
                $this->reserve->assertPayment($device, $order, $payment);
                if (PaymentReversal::query()->where('payment_id', $payment->id)->whereIn('status', ['pending', 'uncertain'])->exists()) {
                    throw new ReversalException('reversal_in_progress');
                }
            } catch (ReversalException $exception) {
                $reason = $exception->codeName;
            }
            $balance = $this->reserve->balance($payment);
            $payments[] = [
                'payment_uuid' => $payment->uuid, 'amount_baisas' => Money::toBaisas($payment->amount),
                'can_void' => $reason === null && ! $hasOpenReversal && $balance > 0 && $this->reserve->canVoidToday($payment),
                'can_refund' => $reason === null && ! $hasOpenVoid && $balance > 0
                    && (! $profile?->refundNeedsTransactionId || trim((string) $payment->softpos_transaction_id) !== ''),
                'refundable_baisas' => $balance, 'refundable_lines' => $lines, 'unavailable_reason' => $reason,
                'void_window_ends_at' => $payment->captured_at?->copy()->timezone(config('pos.business_timezone', 'Asia/Muscat'))
                    ->startOfDay()->addDay()->toIso8601String(),
            ];
        }

        return ['order_uuid' => $order->uuid, 'payments' => $payments];
    }
}
