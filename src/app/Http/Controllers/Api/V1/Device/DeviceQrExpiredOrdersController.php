<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\CancelExpiredQuickOrdersAction;
use App\Actions\Qr\QrChargeException;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceQrExpiredOrdersController
{
    public function preview(Request $request, CancelExpiredQuickOrdersAction $action): JsonResponse
    {
        $input = $request->validate(['order_uuid' => ['sometimes', 'uuid'],
            'exclude_order_uuids' => ['sometimes', 'array', 'max:500'], 'exclude_order_uuids.*' => ['uuid', 'distinct']]);
        /** @var Device $device */
        $device = $request->user();
        try {
            return QrApiResponse::success($action->preview($device, $input['order_uuid'] ?? null,
                array_values($input['exclude_order_uuids'] ?? [])), ['money_unit' => 'baisas']);
        } catch (QrChargeException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e->httpStatus);
        }
    }

    public function cancel(Request $request, CancelExpiredQuickOrdersAction $action): JsonResponse
    {
        $input = $request->validate(['client_request_id' => ['required', 'uuid'], 'preview_token' => ['required', 'string', 'max:1000000'],
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'], 'reason' => ['required', 'string', 'max:200'],
            'prepared_order_uuids' => ['present', 'array'], 'prepared_order_uuids.*' => ['uuid', 'distinct']]);
        /** @var Device $device */
        $device = $request->user();
        try {
            return QrApiResponse::success($action->cancel($device, $input));
        } catch (QrChargeException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e->httpStatus);
        }
    }
}
