<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\VoidWorkspaceBillAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceWorkspaceVoidController
{
    public function __invoke(Request $request, string $uuid, VoidWorkspaceBillAction $action): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $result = $request->isMethod('get') ? $action->preview($device, $uuid)
                : $action->handle($device, $uuid, $request->only(['preview_token', 'pin', 'reason']));
            $response = QrApiResponse::success($result, ['money_unit' => 'baisas']);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
