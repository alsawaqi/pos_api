<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\PublicTableLoyalty;
use App\Models\QrSession;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QrLoyaltyController
{
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->attributes->get('qr_session');
        if (! $session instanceof QrSession) {
            return QrApiResponse::failure('qr_session_not_found', 'QR session was not found.', 404);
        }

        return QrApiResponse::success(['accounts' => PublicTableLoyalty::accounts(PublicTableLoyalty::order($session))]);
    }
}
