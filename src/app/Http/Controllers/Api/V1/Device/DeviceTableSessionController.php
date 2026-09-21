<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\AdjustTableBillAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\CancelStaffLineAction;
use App\Actions\Tables\CancelTableBillAction;
use App\Actions\Tables\CloseStaffTableSessionAction;
use App\Actions\Tables\JoinTableSessionAction;
use App\Actions\Tables\MoveTableSessionAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Http\Requests\Api\V1\Device\StaffTableSessionRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class DeviceTableSessionController
{
    public function __construct(
        private readonly OpenStaffTableSessionAction $open,
        private readonly AppendStaffRoundAction $round,
        private readonly MoveTableSessionAction $move,
        private readonly JoinTableSessionAction $join,
        private readonly CloseStaffTableSessionAction $close,
        private readonly CancelStaffLineAction $cancel_line,
        private readonly CancelTableBillAction $cancel_bill,
        private readonly AdjustTableBillAction $adjust,
    ) {}

    public function __invoke(StaffTableSessionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $uuid = $request->route('uuid');
        if ($uuid !== null && ! Str::isUuid($uuid)) {
            return QrApiResponse::failure('table_session_not_found', 'The seating was not found in this branch.', 404);
        }
        $receivedAt = now();
        $clientAt = isset($payload['client_timestamp']) ? Carbon::parse($payload['client_timestamp']) : $receivedAt;
        $operation = (string) $request->route('table_operation');
        try {
            $result = $operation === 'open'
                ? $this->open->handle($request->user(), $payload, $clientAt, $receivedAt)
                : $this->{$operation}->handle($request->user(), $payload, $clientAt, $receivedAt, $uuid);
        } catch (QrDineInException $exception) {
            $response = QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
            if ($exception->details !== []) {
                $response->setData(array_replace($response->getData(true), ['data' => $exception->details]));
            }

            return $response;
        }

        return QrApiResponse::success($result);
    }
}
