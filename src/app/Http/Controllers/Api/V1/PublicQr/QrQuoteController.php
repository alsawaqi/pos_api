<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrPricePresenter;
use App\Http\Requests\Api\V1\PublicQr\QuoteQrRequest;
use App\Models\QrSession;
use App\Support\Pricing\Totals;
use App\Support\QrApiResponse;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/public/qr/quote. It performs no writes. */
final class QrQuoteController
{
    public function __construct(
        private readonly LoadQrPricingInputAction $pricing,
        private readonly QrPricePresenter $presenter,
    ) {}

    public function __invoke(QuoteQrRequest $request): JsonResponse
    {
        /** @var QrSession $session */
        $session = $request->attributes->get('qr_session');

        try {
            $loaded = $this->pricing->handle(
                (int) $session->company_id,
                (int) $session->branch_id,
                $request->validated('lines'),
                DateTimeImmutable::createFromInterface(now()),
            );
            $quote = $this->presenter->quote($loaded, Totals::priceOrder($loaded->pricingInput));
        } catch (QrCatalogueException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), 422);
        }

        return QrApiResponse::success(['quote' => $quote], ['money_unit' => 'baisas']);
    }
}
