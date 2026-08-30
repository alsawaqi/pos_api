<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\Device\OpenDineInTableRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/device/qr/open-table. */
final class DeviceQrOpenTableController
{
    public function __construct(
        private readonly OpenDineInTableAction $openTable,
    ) {}

    public function __invoke(OpenDineInTableRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $result = $this->openTable->handle(
                $device,
                (int) $request->validated('table_id'),
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success([
            'session_uuid' => (string) $result['session']->uuid,
            'table_token' => $result['table_token'],
            'expires_at' => $result['session']->expires_at?->toIso8601String(),
        ], status: 201);
    }
}
