<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\ReleaseQrChargeAction;
use App\Http\Requests\Api\V1\Device\ReleaseQrChargeRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/device/qr/release-charge. */
final class DeviceQrReleaseChargeController
{
    public function __construct(
        private readonly ReleaseQrChargeAction $release,
    ) {}

    public function __invoke(ReleaseQrChargeRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $result = $this->release->handle($device, $request->validated());
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
