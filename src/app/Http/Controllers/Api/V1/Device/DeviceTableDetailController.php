<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ReadTableDetailAction;
use App\Models\Device;
use App\Support\DeviceCapabilities;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTableDetailController
{
    public function __invoke(Request $request, string $tableId, ReadTableDetailAction $read): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $response = QrApiResponse::success($read->handle($device, (int) $tableId, DeviceCapabilities::tabletOrders($request)), [
                'money_unit' => 'baisas', 'generated_at' => now()->toIso8601String(),
            ]);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
