<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QuickPaymentRecoveryAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceQrPaymentRecoveryController
{
    public function __invoke(Request $request, string $uuid, QuickPaymentRecoveryAction $recovery): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $action = $request->isMethod('post') ? $request->validate(['action' => 'required|in:retry,counter,review'])['action'] : null;
        try {
            return QrApiResponse::success($recovery->station($device, $uuid, $action));
        } catch (QrChargeException $error) {
            return QrApiResponse::failure($error->codeName, $error->getMessage(), $error->httpStatus);
        }
    }
}
