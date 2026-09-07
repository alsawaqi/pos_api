<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\ReleaseTableCredentialAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTableReleaseCredentialController
{
    public function __construct(private readonly ReleaseTableCredentialAction $release) {}

    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            return QrApiResponse::success($this->release->handle($device, $uuid));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }
}
