<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\PublicQr\BindQrTableSessionRequest;
use App\Support\Qr\ForwardedCustomerIp;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/public/qr/table-bind. */
final class QrTableBindController
{
    public function __construct(
        private readonly BindQrTableSessionAction $bind,
        private readonly ForwardedCustomerIp $customerIp,
    ) {}

    public function __invoke(BindQrTableSessionRequest $request): JsonResponse
    {
        try {
            $session = $this->bind->handle(
                (string) $request->validated('table_token'),
                (string) $request->validated('client_secret'),
                $request->safe()->only(['location_state', 'location', 'fingerprint_hash']),
                $this->customerIp->resolve($request),
            );
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }

        if ($session === null) {
            return QrApiResponse::failure(
                'qr_bind_failed',
                'QR session could not be bound.',
                404,
            );
        }

        if (is_array($session)) {
            return QrApiResponse::success($session);
        }

        return QrApiResponse::success([
            'session_uuid' => $session->uuid,
            'status' => $session->status,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);
    }
}
