<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\ListDineInQrTableBoardAction;
use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceQrTableBoardController
{
    public function __construct(private readonly ListDineInQrTableBoardAction $board) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        try {
            $tables = $this->board->handle($device);
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success(['tables' => $tables], [
            'generated_at' => now()->toIso8601String(),
            'money_unit' => 'baisas',
        ]);
    }
}
