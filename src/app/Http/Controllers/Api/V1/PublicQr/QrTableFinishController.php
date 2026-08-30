<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\FinishDineInQrOrderAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\PublicQr\FinishDineInQrRequest;
use App\Models\QrSession;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class QrTableFinishController
{
    public function __construct(private readonly FinishDineInQrOrderAction $finish) {}

    public function __invoke(FinishDineInQrRequest $request): JsonResponse
    {
        /** @var QrSession $session */
        $session = $request->attributes->get('qr_session');

        try {
            $data = $this->finish->handle(
                (int) $session->id,
                (string) $request->validated('payment_choice'),
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($data, ['money_unit' => 'baisas']);
    }
}
