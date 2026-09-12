<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\RecoverTableDraftAction;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTableDraftRecoveryController
{
    public function preview(Request $request, string $tableId, RecoverTableDraftAction $recover): JsonResponse
    {
        try {
            $response = QrApiResponse::success($recover->preview($request->user(), (int) $tableId, $request->query()), ['money_unit' => 'baisas']);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $tableId, RecoverTableDraftAction $recover): JsonResponse
    {
        try {
            $response = QrApiResponse::success(['status' => 'processed',
                'result' => $recover->handle($request->user(), (int) $tableId, $request->all())]);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
            if ($exception->finalNoWrite) {
                $body = $response->getData(true);
                $body['draft_recovery_final_no_write'] = $recover->values($request->all(), true) + ['table_id' => (int) $tableId];
                $response->setData($body);
            }
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
