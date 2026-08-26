<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\BuildQrBranchMenuAction;
use App\Models\QrSession;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/public/qr/menu. */
final class QrMenuController
{
    public function __construct(private readonly BuildQrBranchMenuAction $menu) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var QrSession $session */
        $session = $request->attributes->get('qr_session');

        return QrApiResponse::success(
            $this->menu->handle((int) $session->company_id, (int) $session->branch_id),
            ['money_unit' => 'baisas'],
        );
    }
}
