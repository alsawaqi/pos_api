<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ReadTableDraftProofAction;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTableDraftProofController
{
    public function __invoke(Request $request, string $tableId, ReadTableDraftProofAction $read): JsonResponse
    {
        try {
            $response = QrApiResponse::success($read->handle($request->user(), (int) $tableId, $request->query()), [
                'money_unit' => 'baisas',
            ]);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
