<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\CreateQrOrderAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrCheckoutException;
use App\Actions\Qr\QrPricePresenter;
use App\Http\Requests\Api\V1\PublicQr\CheckoutQrRequest;
use App\Models\QrSession;
use App\Support\Qr\ForwardedCustomerIp;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/public/qr/checkout. */
final class QrCheckoutController
{
    public function __construct(
        private readonly CreateQrOrderAction $orders,
        private readonly QrPricePresenter $presenter,
        private readonly ForwardedCustomerIp $customerIp,
    ) {}

    public function __invoke(CheckoutQrRequest $request): JsonResponse
    {
        /** @var QrSession $session */
        $session = $request->attributes->get('qr_session');

        try {
            $order = $this->orders->handle(
                (int) $session->id,
                $request->validated(),
                $this->customerIp->resolve($request),
            );
        } catch (QrCatalogueException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), 422);
        } catch (QrCheckoutException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return QrApiResponse::success(
            $this->presenter->checkout($order),
            ['money_unit' => 'baisas'],
            201,
        );
    }
}
