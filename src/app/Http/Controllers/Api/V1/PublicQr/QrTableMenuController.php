<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\BuildQrTableMenuAction;
use App\Actions\Qr\QrDineInException;
use App\Http\Requests\Api\V1\PublicQr\TableMenuQrRequest;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/public/qr/table-menu. */
final class QrTableMenuController
{
    public function __construct(
        private readonly BuildQrTableMenuAction $menu,
    ) {}

    public function __invoke(TableMenuQrRequest $request): JsonResponse
    {
        try {
            $menu = $this->menu->handle((string) $request->validated('t'));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success($menu, ['money_unit' => 'baisas']);
    }
}
