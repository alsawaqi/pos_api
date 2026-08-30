<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ClearDineInQrTableAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\Device\ClearDineInQrTableRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQrClearTableController
{
    public function __construct(private readonly ClearDineInQrTableAction $clear) {}

    public function __invoke(ClearDineInQrTableRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $data = $this->clear->handle($device, (int) $request->validated('table_id'));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($data);
    }
}
