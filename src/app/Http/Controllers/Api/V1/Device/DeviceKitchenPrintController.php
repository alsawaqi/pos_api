<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimKitchenTicketAction;
use App\Actions\Tables\RecordKitchenPrintResultAction;
use App\Http\Requests\Api\V1\Device\ClaimKitchenTicketRequest;
use App\Http\Requests\Api\V1\Device\RecordKitchenPrintResultRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceKitchenPrintController
{
    public function claim(ClaimKitchenTicketRequest $request, ClaimKitchenTicketAction $action): JsonResponse
    {
        try {
            return QrApiResponse::success($action->handle($request->user(), $request->validated()), [], 201);
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }

    public function result(RecordKitchenPrintResultRequest $request, RecordKitchenPrintResultAction $action): JsonResponse
    {
        try {
            return QrApiResponse::success($action->handle($request->user(), $request->validated()));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }
}
