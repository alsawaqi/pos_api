<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\FallbackQrOrderToCounterAction;
use App\Actions\Qr\QrChargeException;
use App\Http\Requests\Api\V1\Device\FallbackQrOrderToCounterRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/device/qr/fallback-to-counter. */
final class DeviceQrFallbackToCounterController
{
    public function __construct(
        private readonly FallbackQrOrderToCounterAction $fallback,
    ) {}

    public function __invoke(FallbackQrOrderToCounterRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $result = $this->fallback->handle(
                $device,
                $request->validated('order_uuid'),
            );
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($result);
    }
}
