<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimTableSessionOwnerAction;
use App\Http\Requests\Api\V1\Device\ClaimTableSessionOwnerRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceTableClaimOwnerController
{
    public function __construct(private readonly ClaimTableSessionOwnerAction $claim) {}

    public function __invoke(ClaimTableSessionOwnerRequest $request, string $uuid): JsonResponse
    {
        try {
            $result = $this->claim->handle($request->user(), $uuid);
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success($result);
    }
}
