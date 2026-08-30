<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ClaimQrSettlementAction;
use App\Actions\Qr\QrChargeException;
use App\Http\Requests\Api\V1\Device\ClaimQrSettlementRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/device/qr/claim-settlement. */
final class DeviceQrClaimSettlementController
{
    public function __construct(
        private readonly ClaimQrSettlementAction $claim,
    ) {}

    public function __invoke(ClaimQrSettlementRequest $request): JsonResponse
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
