<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Device\ResolveDeviceSoftPos;
use App\Actions\Device\VerifyManagerPinAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PosStaff;
use App\Models\VoidReason;
use App\Support\Money;
use App\Support\SoftPos\ReversalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReservePaymentReversalAction
{
    public function __construct(
        private readonly ResolveDeviceSoftPos $profiles,
        private readonly VerifyManagerPinAction $pins,
    ) {}

    public function handle(Device $device, string $paymentUuid, array $payload): array
    {
        $this->assertDevice($device->fresh());
        $payment = Payment::query()->where('uuid', $paymentUuid)
            ->whereHas('order', fn ($q) => $q->where('company_id', $device->company_id)->where('branch_id', $device->branch_id))
            ->first();
        if ($payment === null) {
            throw new ReversalException('payment_not_found', 404);
        }
        $payload = $this->normalize($payload);
        $fingerprint = ReversalContract::fingerprint(['payment_uuid' => $paymentUuid] + $payload);

        return DB::transaction(function () use ($device, $payment, $payload, $fingerprint): array {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->assertDevice($device);
            if ((int) $order->company_id !== (int) $device->company_id || (int) $order->branch_id !== (int) $device->branch_id) {
                throw new ReversalException('payment_not_found', 404);
            }
            $existing = PaymentReversal::query()->where('device_id', $device->id)
                ->where('client_request_id', $payload['client_request_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                $this->manager($device, $payload['manager_pin'] ?? '');
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ReversalException('idempotency_conflict');
                }

                return ReversalContract::present($existing);
            }
            $this->assertPayment($device, $order, $payment);
            $manager = $this->manager($device, $payload['manager_pin'] ?? '');
            if (PaymentReversal::query()->where('payment_id', $payment->id)->whereIn('status', ['pending', 'uncertain'])->exists()) {
                throw new ReversalException('reversal_in_progress');
            }
            if (PaymentReversal::query()->where('order_id', $order->id)->whereIn('status', ['pending', 'uncertain'])
                ->when($payload['kind'] !== 'void', fn ($q) => $q->where('kind', 'void'))->exists()) {
                throw new ReversalException('reversal_in_progress');
            }
            $profile = $this->profiles->handle($device);
            $kind = $payload['kind'];
            $lines = [];
            $reason = null;
            if ($kind === 'void') {
                $reason = VoidReason::query()->where('company_id', $device->company_id)->where('is_active', true)
                    ->find($payload['void_reason_id'] ?? 0);
                if ($reason === null) {
                    throw new ReversalException('void_reason_invalid', 422);
                }
                if (! $this->canVoidToday($payment)) {
                    throw new ReversalException('void_window_closed');
                }
                // amount already excludes the separately recorded donation (owner correction).
                $amount = Money::toBaisas($payment->amount);
            } else {
                if ($profile->refundNeedsTransactionId && trim((string) $payment->softpos_transaction_id) === '') {
                    throw new ReversalException('original_reference_missing');
                }
                $hasLines = ($payload['lines'] ?? []) !== [];
                $hasCustom = isset($payload['custom_amount_baisas']);
                if ($hasLines === $hasCustom) {
                    throw new ReversalException('refund_amount_source_invalid', 422);
                }
                if ($hasLines) {
                    $lines = $this->priceLines($order, $payment, $payload['lines']);
                    $amount = array_sum(array_column($lines, 'amount_baisas'));
                } else {
                    $amount = $payload['custom_amount_baisas'];
                }
            }
            if ($amount < 1 || $amount > $this->balance($payment)) {
                throw new ReversalException('refund_exceeds_balance');
            }
            $reversal = PaymentReversal::create([
                'uuid' => (string) Str::uuid(), 'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
                'order_id' => $order->id, 'payment_id' => $payment->id, 'kind' => $kind,
                'amount' => Money::toOmr($amount), 'amount_baisas' => $amount, 'currency_code' => $profile->currency,
                'status' => 'pending', 'softpos_provider' => $profile->provider, 'softpos_package' => $profile->package,
                'refund_needs_transaction_id' => $profile->refundNeedsTransactionId,
                'void_needs_session_id' => $profile->voidNeedsSessionId,
                'bank_id' => $payment->bank_id, 'terminal_id' => $device->terminal_id,
                'original_softpos_transaction_id' => $payment->softpos_transaction_id,
                'reason_code' => $reason?->code ?? $payload['reason_code'],
                'reason_note' => $payload['reason_note'] ?? null, 'void_reason_id' => $reason?->id,
                'approved_by_staff_id' => $manager->id, 'device_id' => $device->id,
                'client_request_id' => $payload['client_request_id'], 'request_fingerprint' => $fingerprint,
                'attempted_at' => now(),
            ]);
            foreach ($lines as $line) {
                DB::table('pos_payment_reversal_lines')->insert(['reversal_id' => $reversal->id] + $line);
            }

            return ReversalContract::present($reversal);
        }, 5);
    }

    public function assertDevice(Device $device): void
    {
        if (! in_array($device->device_type, ['fixed_pos', 'handheld'], true) || ! $device->isAssigned() || $device->status !== 'active') {
            throw new ReversalException('device_not_attended');
        }
        if ($device->card_tenders_blocked_reason !== null) {
            throw new ReversalException('softpos_blocked');
        }
    }

    public function assertPayment(Device $device, Order $order, Payment $payment): void
    {
        if ($payment->method !== 'card' || $payment->direction !== 'sale' || $payment->status !== 'success'
            || $payment->pending_reconciliation || $payment->voided_at !== null || $order->status !== 'paid') {
            throw new ReversalException('payment_not_reversible');
        }
        $profile = $this->profiles->handle($device);
        if ($profile === null || $profile->package === null || $profile->provider !== $payment->softpos_provider
            || $profile->bankId !== (int) $payment->bank_id) {
            throw new ReversalException('reversal_bank_mismatch');
        }
    }

    public function balance(Payment $payment): int
    {
        return max(0, Money::toBaisas($payment->amount) - Money::toBaisas($payment->refunded_total));
    }

    public function canVoidToday(Payment $payment): bool
    {
        $zone = config('pos.business_timezone', 'Asia/Muscat');

        return $payment->captured_at !== null
            && $payment->captured_at->copy()->timezone($zone)->toDateString() === now($zone)->toDateString()
            && ! PaymentReversal::query()->where('order_id', $payment->order_id)->where('status', 'approved')->exists();
    }

    public function remainingLines(Order $order): array
    {
        $items = DB::table('pos_order_items')->where('order_id', $order->id)->where('status', '<>', 'void')->orderBy('id')->get();
        $reserved = DB::table('pos_payment_reversal_lines as l')
            ->join('pos_payment_reversals as r', 'r.id', '=', 'l.reversal_id')
            ->where('r.order_id', $order->id)->whereIn('r.status', ['approved', 'pending', 'uncertain'])
            ->selectRaw('l.order_item_id, SUM(l.qty) as qty')->groupBy('l.order_item_id')->pluck('qty', 'order_item_id');
        $result = [];
        foreach ($items as $item) {
            $sold = Money::toBaisas($item->qty);
            $remaining = max(0, $sold - Money::toBaisas($reserved[$item->id] ?? 0));
            if ($sold <= 0 || $remaining <= 0) {
                continue;
            }
            [$weight] = ReversalMoney::fraction(max(0, Money::toBaisas($item->line_total)), $remaining, $sold);
            $result[$item->id] = ['item' => $item, 'remaining' => $remaining, 'weight' => $weight];
        }

        return $result;
    }

    private function priceLines(Order $order, Payment $payment, array $requested): array
    {
        $remaining = $this->remainingLines($order);
        $weights = array_map(static fn ($row): int => $row['weight'], $remaining);
        $allocation = ReversalMoney::allocate($this->balance($payment), $weights);
        $result = [];
        $seen = [];
        foreach ($requested as $line) {
            $id = $line['order_item_id'];
            $qty = Money::toBaisas($line['qty']);
            if (isset($seen[$id]) || ! isset($remaining[$id]) || $qty <= 0 || $qty > $remaining[$id]['remaining']) {
                throw new ReversalException('refund_qty_exceeds_sold');
            }
            $seen[$id] = true;
            [$amount] = ReversalMoney::fraction($allocation[$id], $qty, $remaining[$id]['remaining']);
            $product = DB::table('pos_products')->where('id', $remaining[$id]['item']->product_id)
                ->where('company_id', $order->company_id)->first();
            $result[] = [
                'order_item_id' => $id, 'qty' => Money::toOmr($qty),
                'amount' => Money::toOmr($amount), 'amount_baisas' => $amount,
                'stock_mode_at_refund' => $product?->stock_mode ?? 'untracked',
                'returned_to_stock' => false,
            ];
        }

        return $result;
    }

    private function manager(Device $device, string $pin): PosStaff
    {
        if ($pin === '') {
            throw new ReversalException('invalid_manager_pin', 401);
        }
        try {
            return $this->pins->verify($device, $pin);
        } catch (RuntimeException) {
            throw new ReversalException('invalid_manager_pin', 401);
        }
    }

    private function normalize(array $payload): array
    {
        if (! in_array($payload['kind'] ?? null, ['void', 'refund'], true)) {
            throw new ReversalException('invalid_reversal_kind', 422);
        }
        if (isset($payload['lines'])) {
            $payload['lines'] = array_map(static fn (array $line): array => [
                'order_item_id' => (int) $line['order_item_id'],
                'qty' => Money::toOmr(Money::toBaisas($line['qty'])),
            ], $payload['lines']);
            usort($payload['lines'], static fn ($a, $b): int => $a['order_item_id'] <=> $b['order_item_id']);
        }
        if (isset($payload['custom_amount_baisas'])) {
            $payload['custom_amount_baisas'] = (int) $payload['custom_amount_baisas'];
        }

        return $payload;
    }
}
