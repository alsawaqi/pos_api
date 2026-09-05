<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ListTableSessionFeedAction;
use App\Http\Requests\Api\V1\Device\ListTableSessionFeedRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceTableFeedController
{
    public function __construct(private readonly ListTableSessionFeedAction $feed) {}

    public function __invoke(ListTableSessionFeedRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $validated = $request->validated();
        try {
            $result = $this->feed->handle(
                $device,
                isset($validated['after']) ? (int) $validated['after'] : null,
                isset($validated['limit']) ? (int) $validated['limit'] : 50,
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success(['events' => $result['events']], [
            'latest_id' => $result['latest_id'], 'has_more' => $result['has_more'],
        ]);
    }
}
