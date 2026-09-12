<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ListOrderAttentionAction;
use App\Actions\Qr\QrChargeException;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceOrderAttentionController
{
    public function __invoke(Request $request, ListOrderAttentionAction $list): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            return QrApiResponse::success($list->handle($device));
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }
}
