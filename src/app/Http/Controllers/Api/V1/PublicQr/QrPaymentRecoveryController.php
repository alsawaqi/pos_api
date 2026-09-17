<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QuickPaymentRecoveryAction;
use App\Models\QrSession;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QrPaymentRecoveryController
{
    public function __invoke(Request $request, QuickPaymentRecoveryAction $recovery): JsonResponse
    {
        $session = $request->attributes->get('qr_session');
        if (! $session instanceof QrSession) {
            return QrApiResponse::failure('qr_session_not_found', 'QR session was not found.', 404);
        }
        $action = $request->isMethod('post') ? $request->validate(['action' => 'required|in:retry,counter,review'])['action'] : null;
        try {
            return QrApiResponse::success($recovery->customer($session, $action));
        } catch (QrChargeException $error) {
            return QrApiResponse::failure($error->codeName, $error->getMessage(), $error->httpStatus);
        }
    }
}
