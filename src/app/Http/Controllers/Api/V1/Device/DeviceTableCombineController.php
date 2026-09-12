<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\CombineLegacyTableBillAction;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTableCombineController
{
    public function preview(Request $request, string $tableId, CombineLegacyTableBillAction $combine): JsonResponse
    {
        $input = $request->validate(['source_order_uuid' => ['required', 'uuid']]);
        try {
            $response = QrApiResponse::success($combine->preview($request->user(), (int) $tableId, $input['source_order_uuid']));
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $tableId, CombineLegacyTableBillAction $combine): JsonResponse
    {
        try {
            $result = $combine->handle($request->user(), (int) $tableId, $request->all());
            $response = QrApiResponse::success(['status' => 'processed', 'result' => $result]);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
            if ($exception->finalNoWrite) {
                $body = $response->getData(true);
                $body['combine_final_no_write'] = $request->only([
                    'source_order_uuid', 'target_order_uuid', 'client_request_id', 'preview_token', 'reason',
                ]) + ['table_id' => (int) $tableId];
                $response->setData($body);
            }
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
