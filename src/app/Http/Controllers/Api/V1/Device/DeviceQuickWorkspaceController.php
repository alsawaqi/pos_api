<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QuickQrWorkspaceAction;
use App\Http\Requests\Api\V1\Device\QuickWorkspaceRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQuickWorkspaceController
{
    public function __invoke(QuickWorkspaceRequest $request, string $uuid, QuickQrWorkspaceAction $action): JsonResponse
    {
        try {
            return QrApiResponse::success($action->handle($request->user(), $uuid, $request->validated()));
        } catch (QrChargeException|QrCatalogueException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e instanceof QrCatalogueException ? 422 : $e->httpStatus);
        }
    }
}
