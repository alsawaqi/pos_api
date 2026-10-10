<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\Device\DeviceQrRoundRequest;
use App\Kitchen\CloudIntake;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQrConfirmRoundController
{
    public function __construct(private readonly ConfirmDineInQrRoundAction $confirm) {}

    public function __invoke(DeviceQrRoundRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $roundId = (int) $request->validated('round_id');
            $data = app(CloudIntake::class)->review($request,
                fn () => $this->confirm->handle($device, $roundId), 'approve', roundId: $roundId);
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
