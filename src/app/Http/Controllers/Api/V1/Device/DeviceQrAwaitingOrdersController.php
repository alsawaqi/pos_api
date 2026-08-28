<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ListStationQrAwaitingOrdersAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/device/qr/awaiting-orders. */
final class DeviceQrAwaitingOrdersController
{
    public function __construct(
        private readonly ListStationQrAwaitingOrdersAction $orders,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isPaymentStation()) {
            return QrApiResponse::failure(
                'device_not_payment_station',
                'Only a payment station may read awaiting QR orders.',
                409,
            );
        }
        if (! $device->isAssigned()) {
            return QrApiResponse::failure(
                'device_unassigned',
                'This device is not assigned to a branch.',
                409,
            );
        }
        if ($device->status !== 'active') {
            return QrApiResponse::failure(
                'device_not_active',
                'This payment station is not active.',
                409,
            );
        }

        return QrApiResponse::success([
            'orders' => $this->orders->handle($device),
        ], [
            'generated_at' => now()->toIso8601String(),
            'money_unit' => 'baisas',
        ]);
    }
}
