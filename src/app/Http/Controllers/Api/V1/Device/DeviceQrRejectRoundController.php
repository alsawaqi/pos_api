<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RejectDineInQrRoundAction;
use App\Http\Requests\Api\V1\Device\DeviceQrRoundRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQrRejectRoundController
{
    public function __construct(private readonly RejectDineInQrRoundAction $reject) {}

    public function __invoke(DeviceQrRoundRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $data = $this->reject->handle(
                $device,
                (int) $request->validated('round_id'),
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($data, ['money_unit' => 'baisas']);
    }
}
