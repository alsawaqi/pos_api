<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Preserve a station charge attempt by stamping its terminal outcome. */
final class ReleaseQrChargeAction
{
    /**
     * @param  array{
     *     order_uuid: string,
     *     outcome: string,
     *     softpos_reference?: string|null,
     *     softpos_auth_code?: string|null,
     *     bank_response?: array<string, mixed>|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload): array
    {
        $orderUuid = trim($payload['order_uuid']);
        $outcome = $payload['outcome'];
        $reference = $this->optionalString($payload['softpos_reference'] ?? null);
        $authCode = $this->optionalString($payload['softpos_auth_code'] ?? null);

        $result = DB::transaction(function () use ($device, $orderUuid, $outcome): array {
            $order = Order::query()
                ->where('uuid', $orderUuid)
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }

            if ($order->qr_session_id !== null) {
                QrSession::query()
                    ->whereKey((int) $order->qr_session_id)
                    ->lockForUpdate()
                    ->first();
            }

            if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
                throw new QrChargeException(
                    'order_not_awaiting_payment',
                    409,
                    'The order is not awaiting payment.',
                );
            }
            if ($order->charge_claimed_at === null
                || $order->charge_device_id === null
                || (int) $order->charge_device_id !== (int) $device->getKey()) {
                throw new QrChargeException(
                    'charge_not_claimed_by_device',
                    409,
                    'This device does not hold the order charge claim.',
                );
            }
            if ($order->charge_outcome !== null) {
                $currentOutcome = (string) $order->charge_outcome;

                throw new QrChargeException(
                    $currentOutcome === Order::CHARGE_OUTCOME_UNCERTAIN
                        ? 'charge_outcome_uncertain'
                        : 'charge_already_claimed',
                    409,
                    "The charge outcome is already {$currentOutcome} and cannot be overwritten.",
                );
            }

            // Preserve the frozen round-up intent: an API release says only
            // that this claim stopped being live. A delayed SoftPOS approval
            // may still arrive and must carry its donation into reconciliation.
            $order->update(['charge_outcome' => $outcome]);

            return [
                'order_uuid' => (string) $order->uuid,
                'charge_amount_baisas' => (int) $order->charge_amount_baisas,
                'charge_claimed_at' => $order->charge_claimed_at?->toIso8601String(),
                'charge_deadline_at' => $order->charge_deadline_at?->toIso8601String(),
                'charge_outcome' => $outcome,
            ];
        });

        $context = [
            'order_uuid' => $result['order_uuid'],
            'device_id' => (int) $device->getKey(),
            'charge_amount_baisas' => $result['charge_amount_baisas'],
            'outcome' => $outcome,
            'softpos_reference' => $reference,
            'softpos_auth_code' => $authCode,
        ];

        if ($outcome === Order::CHARGE_OUTCOME_UNCERTAIN) {
            Log::warning('qr-charge release', $context);
        } else {
            Log::info('qr-charge release', $context);
        }

        return $result;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
