<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\AppendQuickQrOrderItemsAction;
use App\Actions\Qr\ListQrPendingOrdersAction;
use App\Actions\Qr\MoveQuickQrOrderToCounterAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrChargeException;
use App\Http\Requests\Api\V1\Device\AppendQuickQrOrderItemsRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceQrPendingOrdersController
{
    public function appendItems(AppendQuickQrOrderItemsRequest $request, string $uuid, AppendQuickQrOrderItemsAction $append): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $result = $append->handle($device, $uuid, $request->validated());
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        } catch (QrCatalogueException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), 422);
        }

        return QrApiResponse::success($result, ['money_unit' => 'baisas']);
    }

    public function toCounter(Request $request, string $uuid, MoveQuickQrOrderToCounterAction $move): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $row = $move->handle($device, $uuid);
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success($row, ['money_unit' => 'baisas']);
    }

    public function index(Request $request, ListQrPendingOrdersAction $list): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $orders = $list->handle($device);
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success(['orders' => $orders], [
            'generated_at' => now()->toIso8601String(),
            'money_unit' => 'baisas',
        ]);
    }
}
