<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicQr;

use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Http\Requests\Api\V1\PublicQr\SubmitDineInQrRoundRequest;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Qr\ForwardedCustomerIp;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class QrTableRoundController
{
    public function __construct(
        private readonly SubmitDineInQrRoundAction $rounds,
        private readonly ForwardedCustomerIp $customerIp,
    ) {}

    public function __invoke(SubmitDineInQrRoundRequest $request): JsonResponse
    {
        /** @var QrSession $session */
        $session = $request->attributes->get('qr_session');

        try {
            $result = $this->rounds->handle(
                (int) $session->id,
                $request->validated(),
                $this->customerIp->resolve($request),
            );
        } catch (QrCatalogueException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), 422);
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure(
                $exception->codeName,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        /** @var QrOrderRound $round */
        $round = $result['round'];
        /** @var Order $order */
        $order = $result['order'];

        return QrApiResponse::success([
            'round' => [
                'id' => (int) $round->id,
                'round_no' => (int) $round->round_no,
                'status' => (string) $round->status,
                'client_request_id' => (string) $round->client_request_id,
                'priced_lines' => array_map(static fn (array $line): array => array_diff_key(
                    $line, array_flip(['order_item_id', 'cancellations']),
                ), $round->priced_lines ?? []),
                'subtotal_baisas' => (int) $round->subtotal_baisas,
                'tax_baisas' => (int) $round->tax_baisas,
                'total_baisas' => (int) $round->total_baisas,
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'resolved_at' => $round->resolved_at?->toIso8601String(),
            ],
            'order' => [
                'uuid' => (string) $order->uuid,
                'status' => (string) $order->status,
                'receipt_number' => $order->receipt_number,
                'temp_reference' => $order->temp_reference,
                'subtotal_baisas' => Money::toBaisas($order->subtotal),
                'discount_total_baisas' => Money::toBaisas($order->discount_total),
                'tax_total_baisas' => Money::toBaisas($order->tax_total),
                'grand_total_baisas' => Money::toBaisas($order->grand_total),
                // LAUNCH-P4 — true: the tax is already inside grand_total.
                'prices_include_tax' => (bool) $order->prices_include_tax,
            ],
            'replayed' => $result['replayed'],
        ], ['money_unit' => 'baisas'], 201);
    }
}
