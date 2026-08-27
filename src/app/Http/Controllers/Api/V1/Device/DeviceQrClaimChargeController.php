<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ClaimQrChargeAction;
use App\Actions\Qr\QrChargeException;
use App\Http\Requests\Api\V1\Device\ClaimQrChargeRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/device/qr/claim-charge. */
final class DeviceQrClaimChargeController
{
    public function __construct(
        private readonly ClaimQrChargeAction $claim,
    ) {}

    public function __invoke(ClaimQrChargeRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $result = $this->claim->handle($device, $request->validated());
        } catch (QrChargeException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($result, ['money_unit' => 'baisas']);
    }
}
