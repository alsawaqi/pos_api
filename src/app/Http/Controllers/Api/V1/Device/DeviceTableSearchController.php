<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\SearchTableSessionsAction;
use App\Http\Requests\Api\V1\Device\SearchTableSessionsRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceTableSearchController
{
    public function __construct(private readonly SearchTableSessionsAction $search) {}

    public function __invoke(SearchTableSessionsRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $tables = $this->search->handle($device, (string) $request->validated('q'));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success(['tables' => $tables], ['money_unit' => 'baisas']);
    }
}
