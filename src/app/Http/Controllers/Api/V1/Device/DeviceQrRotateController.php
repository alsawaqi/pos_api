<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\RotateQrSessionAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** POST /api/v1/device/qr/rotate. */
class DeviceQrRotateController
{
    public function __construct(
        private readonly RotateQrSessionAction $rotate,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $session = $this->rotate->handle($device);
        } catch (RuntimeException) {
            return QrApiResponse::failure(
                'device_unassigned',
                'This device is not assigned to a branch.',
                409,
            );
        }

        return QrApiResponse::success([
            'token' => $session->token,
            'token_expires_at' => $session->token_expires_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);
    }
}
