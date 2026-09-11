<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\ReadQrCheckoutAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceQrCheckoutController
{
    public function __invoke(Request $request, string $uuid, ReadQrCheckoutAction $read): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $response = QrApiResponse::success($read->handle($device, $uuid), ['money_unit' => 'baisas']);
        } catch (QrChargeException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
