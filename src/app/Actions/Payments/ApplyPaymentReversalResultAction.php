<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\PaymentReversal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mirrored in pos_admin. Lock order: order, original payment, device, reversal. */
final class ApplyPaymentReversalResultAction
{
    public function __construct(private readonly ApplyReversalOrderEffectsAction $effects) {}

    public function handle(string $uuid, array $payload, ?int $deviceId = null, ?int $adminId = null): array
    {
        if (! in_array($payload['status'] ?? null, ['approved', 'declined', 'uncertain', 'cancelled'], true)) {
            throw new ReversalException('invalid_reversal_result', 422);
        }
        $found = PaymentReversal::query()->where('uuid', $uuid)
            ->when($deviceId !== null, fn ($q) => $q->where('device_id', $deviceId))->first();
        if ($found === null) {
            throw new ReversalException('reversal_not_found', 404);
        }
        $fingerprint = ReversalContract::fingerprint(['reversal_uuid' => $uuid] + $payload);

        return DB::transaction(function () use ($found, $payload, $deviceId, $adminId, $fingerprint): array {
            $order = DB::table('pos_orders')->where('id', $found->order_id)->lockForUpdate()->first();
            $payment = DB::table('pos_payments')->where('id', $found->payment_id)->lockForUpdate()->first();
            DB::table('pos_devices')->where('id', $found->device_id)->lockForUpdate()->first();
            $reversal = PaymentReversal::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($deviceId !== null) {
                $existing = DB::table('pos_payment_reversal_results')->where('device_id', $deviceId)
                    ->where('client_request_id', $payload['client_request_id'])->first();
                if ($existing !== null) {
                    if ($existing->request_fingerprint !== $fingerprint) {
                        throw new ReversalException(in_array($reversal->status, ['approved', 'declined', 'cancelled'], true)
                            ? 'reversal_already_closed' : 'idempotency_conflict');
                    }

                    return json_decode($existing->response_json, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $status = $payload['status'];
            $recovery = $reversal->status === 'uncertain'
                && in_array($status, ['approved', 'declined'], true)
                && ($adminId !== null || ! empty($payload['receipt_json']));
            if (($adminId !== null && ! $recovery)
                || ($reversal->status !== 'pending' && ! $recovery)) {
                throw new ReversalException('reversal_already_closed');
            }
            $now = now();
            $reversal->forceFill([
                'status' => $status,
                'completed_at' => $status === 'uncertain' ? null : $now,
                'response_code' => $payload['response_code'] ?? $reversal->response_code,
                'response_description' => $payload['description'] ?? $reversal->response_description,
                'receipt_json' => $payload['receipt_json'] ?? $reversal->receipt_json,
                'reversal_softpos_transaction_id' => $payload['reversal_transaction_id'] ?? $reversal->reversal_softpos_transaction_id,
                'reversal_rrn' => $payload['rrn'] ?? $reversal->reversal_rrn,
                'reversal_auth_code' => $payload['auth_code'] ?? $reversal->reversal_auth_code,
                'resolved_by_user_id' => $adminId,
                'resolved_note' => $payload['evidence_note'] ?? null,
            ])->save();
            $bankResponse = is_string($payment->bank_response) ? json_decode($payment->bank_response, true) : $payment->bank_response;
            $bankResponse = is_array($bankResponse) ? $bankResponse : [];
            if ($status === 'uncertain') {
                $bankResponse['reversal_pending_review'] = true;
            } else {
                unset($bankResponse['reversal_pending_review']);
            }
            if ($status === 'uncertain' || str_contains((string) $payment->bank_response, '"reversal_pending_review"')) {
                DB::table('pos_payments')->where('id', $payment->id)->update([
                    'bank_response' => json_encode($bankResponse, JSON_THROW_ON_ERROR), 'updated_at' => $now,
                ]);
            }
            if ($status === 'approved') {
                $ledgerId = DB::table('pos_payments')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'method' => 'card',
                    'status' => 'success', 'direction' => 'reversal', 'amount' => Money::toOmr(-$reversal->amount_baisas),
                    'pending_reconciliation' => false, 'reversal_id' => $reversal->id,
                    'device_id' => $reversal->device_id, 'bank_id' => $reversal->bank_id, 'terminal_id' => $reversal->terminal_id,
                    'softpos_provider' => $reversal->softpos_provider, 'softpos_package' => $reversal->softpos_package,
                    'softpos_reference' => $reversal->reversal_softpos_transaction_id,
                    'softpos_transaction_id' => $reversal->reversal_softpos_transaction_id,
                    'softpos_auth_code' => $reversal->reversal_auth_code, 'softpos_rrn' => $reversal->reversal_rrn,
                    'bank_response' => $reversal->receipt_json === null ? null : json_encode($reversal->receipt_json, JSON_THROW_ON_ERROR),
                    'captured_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $reversal->forceFill(['ledger_payment_id' => $ledgerId])->save();
                if ($reversal->kind === 'void') {
                    DB::table('pos_payments')->where('id', $payment->id)->update(['voided_at' => $now, 'updated_at' => $now]);
                    $this->effects->void($order, $reversal);
                } else {
                    $paymentRefunded = Money::toBaisas($payment->refunded_total) + $reversal->amount_baisas;
                    $orderRefunded = Money::toBaisas($order->refunded_total) + $reversal->amount_baisas;
                    DB::table('pos_payments')->where('id', $payment->id)->update([
                        'refunded_total' => Money::toOmr($paymentRefunded), 'updated_at' => $now,
                    ]);
                    $singleTender = DB::table('pos_payments')->where('order_id', $order->id)
                        ->where('direction', 'sale')->where('status', 'success')->count() === 1;
                    $full = $singleTender && $paymentRefunded >= Money::toBaisas($payment->amount);
                    DB::table('pos_orders')->where('id', $order->id)->update([
                        'refunded_total' => Money::toOmr($orderRefunded),
                        'status' => $full ? 'refunded' : $order->status, 'updated_at' => $now,
                    ]);
                    $this->effects->refund($order, $reversal, $full);
                }
            }
            $response = ReversalContract::present($reversal->fresh());
            if ($deviceId !== null) {
                DB::table('pos_payment_reversal_results')->insert([
                    'reversal_id' => $reversal->id, 'device_id' => $deviceId,
                    'client_request_id' => $payload['client_request_id'], 'request_fingerprint' => $fingerprint,
                    'response_json' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);
            }

            return $response;
        }, 5);
    }
}
