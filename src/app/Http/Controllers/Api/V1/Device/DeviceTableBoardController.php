<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ListTableBoardAction;
use App\Http\Requests\Api\V1\Device\ListTableBoardRequest;
use App\Models\Device;
use App\Support\DeviceCapabilities;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceTableBoardController
{
    public function __construct(private readonly ListTableBoardAction $board) {}

    public function __invoke(ListTableBoardRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        try {
            $tables = $this->board->handle($device, DeviceCapabilities::tabletOrders($request));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return QrApiResponse::success(['tables' => $tables], [
            'generated_at' => now()->toIso8601String(), 'money_unit' => 'baisas',
        ]);
    }
}
