<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\ReopenDineInQrPaymentAction;
use App\Http\Requests\Api\V1\Device\ReopenDineInQrPaymentRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQrReopenPaymentController
{
    public function __construct(private readonly ReopenDineInQrPaymentAction $reopen) {}

    public function __invoke(ReopenDineInQrPaymentRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $data = $this->reopen->handle($device, (string) $request->validated('order_uuid'));
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
