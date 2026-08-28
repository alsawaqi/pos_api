<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\GeofenceGuard;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Atomically admits and freezes a payment-station charge.
 *
 * The two-numbers rule is deliberate:
 *
 * charge_amount_baisas  = the frozen merchant sale and the order.pay tender.
 * roundup_amount_baisas = the optional donation slice recorded later.
 * softpos_amount_baisas = sale plus round-up, handed only to the SoftPOS APK.
 */
final class ClaimQrChargeAction
{
    public function __construct(
        private readonly GeofenceGuard $geofence,
    ) {}

    /**
     * @param  array{
     *     order_uuid: string,
     *     roundup_amount_baisas?: int|float|string,
     *     gps?: array{lat: int|float|string, lng: int|float|string}|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function handle(Device $authenticatedDevice, array $payload): array
    {
        $orderUuid = trim($payload['order_uuid']);
        $roundupBaisas = (int) ($payload['roundup_amount_baisas'] ?? 0);
        $gps = is_array($payload['gps'] ?? null)
            ? ['lat' => (float) $payload['gps']['lat'], 'lng' => (float) $payload['gps']['lng']]
            : null;

        $result = DB::transaction(function () use (
            $authenticatedDevice,
            $orderUuid,
            $roundupBaisas,
            $gps,
        ): array {
            $now = now();
            $order = Order::query()
                ->where('uuid', $orderUuid)
                ->where('company_id', $authenticatedDevice->company_id)
                ->where('branch_id', $authenticatedDevice->branch_id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }
            if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
                throw new QrChargeException(
                    'order_not_awaiting_payment',
                    409,
                    'The order is not awaiting payment.',
                );
            }

            $session = $this->lockSession($order);
            $claimIsLive = $this->hasLiveClaim($order, $now);
            if ($claimIsLive) {
                if ($order->charge_outcome === null
                    && $order->charge_device_id !== null
                    && (int) $order->charge_device_id === (int) $authenticatedDevice->getKey()) {
                    $this->assertStationAdmission($session, $authenticatedDevice);

                    return $this->present($order, true);
                }

                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'The order already has a live charge claim.',
                );
            }

            if (! $this->isWithoutLiveClaim($order, $now)) {
                throw new QrChargeException(
                    'charge_already_claimed',
                    409,
                    'The order is not available for a new charge claim.',
                );
            }
            $this->assertStationAdmission($session, $authenticatedDevice);

            $this->enforceClaimGeofence($authenticatedDevice, $gps);

            $claimSeconds = max(1, (int) config('qr.charge_claim_seconds', 180));
            $order->update([
                'charge_device_id' => $authenticatedDevice->getKey(),
                'charge_amount_baisas' => Money::toBaisas($order->grand_total),
                'charge_roundup_amount_baisas' => $roundupBaisas,
                'charge_claimed_at' => $now,
                'charge_deadline_at' => $now->copy()->addSeconds($claimSeconds),
                'charge_outcome' => null,
            ]);

            return $this->present($order->refresh(), false);
        });

        Log::info('qr-charge claim', [
            'order_uuid' => $result['order_uuid'],
            'device_id' => (int) $authenticatedDevice->getKey(),
            'charge_amount_baisas' => $result['charge_amount_baisas'],
            'outcome' => null,
            'already_claimed_by_this_device' => $result['already_claimed_by_this_device'],
        ]);

        return $result;
    }

    private function hasLiveClaim(Order $order, CarbonInterface $at): bool
    {
        return Order::query()
            ->whereKey($order->getKey())
            ->withLiveClaim($at)
            ->exists();
    }

    private function isWithoutLiveClaim(Order $order, CarbonInterface $at): bool
    {
        return Order::query()
            ->whereKey($order->getKey())
            ->withoutLiveClaim($at)
            ->exists();
    }

    private function lockSession(Order $order): ?QrSession
    {
        if ($order->qr_session_id === null) {
            return null;
        }

        return QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->lockForUpdate()
            ->first();
    }

    private function assertBoundSession(?QrSession $session, Device $device): QrSession
    {
        if ($session === null
            || (int) $session->company_id !== (int) $device->company_id
            || (int) $session->branch_id !== (int) $device->branch_id
            || (int) $session->device_id !== (int) $device->getKey()) {
            throw new QrChargeException(
                'order_not_bound_to_device_session',
                409,
                'The order is not bound to this station session.',
            );
        }

        return $session;
    }

    private function assertStationAdmission(?QrSession $session, Device $device): void
    {
        if (! $device->isPaymentStation()) {
            throw new QrChargeException(
                'device_not_payment_station',
                409,
                'Only a payment station may claim this charge.',
            );
        }

        $session = $this->assertBoundSession($session, $device);
        if ($session->status !== QrSession::STATUS_ORDERED) {
            throw new QrChargeException(
                'session_not_ordered',
                409,
                'The QR session is not in the ordered state.',
            );
        }
    }

    /**
     * @param  array{lat: float, lng: float}|null  $gps
     */
    private function enforceClaimGeofence(Device $device, ?array $gps): void
    {
        $branch = Branch::query()
            ->whereKey((int) $device->branch_id)
            ->where('company_id', (int) $device->company_id)
            ->first();

        if ($branch === null || ! $this->geofence->isFenced($branch)) {
            return;
        }

        if ($gps !== null) {
            try {
                $this->geofence->assertWithin($branch, $gps['lat'], $gps['lng']);
            } catch (RuntimeException $exception) {
                throw new QrChargeException(
                    'geofence_outside',
                    409,
                    $exception->getMessage(),
                );
            }

            return;
        }

        if (config('qr.station_geofence_exempt') === true) {
            Log::info('qr-charge geofence skipped by station config', [
                'device_id' => (int) $device->getKey(),
                'branch_id' => (int) $device->branch_id,
            ]);

            return;
        }

        throw new QrChargeException(
            'geofence_fix_required',
            409,
            'A GPS fix is required at this geofenced branch.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order, bool $alreadyClaimed): array
    {
        $chargeBaisas = (int) $order->charge_amount_baisas;
        $roundupBaisas = (int) ($order->charge_roundup_amount_baisas ?? 0);

        return [
            'order_uuid' => (string) $order->uuid,
            'charge_amount_baisas' => $chargeBaisas,
            'roundup_amount_baisas' => $roundupBaisas,
            'softpos_amount_baisas' => $chargeBaisas + $roundupBaisas,
            'charge_claimed_at' => $order->charge_claimed_at?->toIso8601String(),
            'charge_deadline_at' => $order->charge_deadline_at?->toIso8601String(),
            'already_claimed_by_this_device' => $alreadyClaimed,
        ];
    }
}
