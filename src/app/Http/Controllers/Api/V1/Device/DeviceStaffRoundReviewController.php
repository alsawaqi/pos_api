<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Actions\Tables\RejectStaffRoundAction;
use App\Http\Requests\Api\V1\Device\StaffRoundReviewRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceStaffRoundReviewController
{
    public function __construct(
        private readonly ConfirmStaffRoundAction $confirmRound,
        private readonly RejectStaffRoundAction $rejectRound,
    ) {}

    public function confirm(StaffRoundReviewRequest $request, string $uuid, int $roundId): JsonResponse
    {
        try {
            return QrApiResponse::success($this->confirmRound->handle($request->user(), $uuid, $roundId));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }

    public function reject(StaffRoundReviewRequest $request, string $uuid, int $roundId): JsonResponse
    {
        try {
            return QrApiResponse::success($this->rejectRound->handle($request->user(), $uuid, $roundId));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }
}
