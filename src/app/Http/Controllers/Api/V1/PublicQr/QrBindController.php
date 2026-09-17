<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\BindQrSessionAction;
use App\Http\Requests\Api\V1\PublicQr\BindQrSessionRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/public/qr/bind. */
class QrBindController
{
    public function __construct(
        private readonly BindQrSessionAction $bind,
    ) {}

    public function __invoke(BindQrSessionRequest $request): JsonResponse
    {
        $session = $this->bind->handle(
            (string) $request->validated('token'),
            (string) $request->validated('client_secret'),
        );

        if ($session === null) {
            return QrApiResponse::failure(
                'qr_bind_failed',
                'QR session could not be bound.',
                404,
            );
        }

        return QrApiResponse::success([
            'session_uuid' => $session->uuid,
            'status' => $session->status,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ], [
            // An opaque comparison key, not authorization to read an order.
            'recovery_scope' => hash_hmac(
                'sha256',
                $session->company_id.':'.$session->branch_id,
                (string) config('app.key'),
            ),
        ]);
    }
}
