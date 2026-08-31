<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ListAcceptedDineInQrRoundsAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\Device\ListAcceptedQrRoundsRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceQrAcceptedRoundsController
{
    public function __construct(private readonly ListAcceptedDineInQrRoundsAction $rounds) {}

    public function __invoke(ListAcceptedQrRoundsRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $validated = $request->validated();

        try {
            $result = $this->rounds->handle(
                $device,
                isset($validated['after']) ? (string) $validated['after'] : null,
                isset($validated['limit']) ? (int) $validated['limit'] : 25,
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success(['rounds' => $result['rounds']], [
            'next_cursor' => $result['next_cursor'],
            'latest_cursor' => $result['latest_cursor'],
            'skipped_expired_count' => $result['skipped_expired_count'],
            'money_unit' => 'baisas',
        ]);
    }
}
