<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClearEmptyTableSessionAction;
use App\Http\Requests\Api\V1\Device\ClearEmptyTableSessionRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceClearEmptyTableSessionController
{
    public function __invoke(ClearEmptyTableSessionRequest $request, ClearEmptyTableSessionAction $action): JsonResponse
    {
        try {
            return QrApiResponse::success($action->handle($request->user(),
                (int) $request->validated('table_id'), (string) $request->validated('seating_uuid')));
        } catch (QrDineInException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e->httpStatus);
        }
    }
}
