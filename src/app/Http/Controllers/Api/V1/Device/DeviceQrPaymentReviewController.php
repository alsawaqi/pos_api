<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\ReviewQuickPaymentAction;
use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DeviceQrPaymentReviewController
{
    public function __invoke(Request $request, string $uuid, ReviewQuickPaymentAction $action): JsonResponse
    {
        $input = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'decision' => ['required', Rule::in(['paid', 'not_paid'])],
            'method' => ['required_if:decision,paid', 'prohibited_if:decision,not_paid', Rule::in(['cash', 'card'])],
            'amount_baisas' => ['required_if:decision,paid', 'prohibited_if:decision,not_paid', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:64'],
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'local_attempt_ids' => ['sometimes', 'array', 'max:20'],
            'local_attempt_ids.*' => ['string', 'max:64', 'distinct'],
            'local_summary' => ['sometimes', 'string', 'max:500'],
            'gps' => ['sometimes', 'array'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
        ]);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return QrApiResponse::failure('order_not_found', 'The order was not found in this branch.', 404);
        }
        /** @var Device $device */
        $device = $request->user();
        try {
            return QrApiResponse::success($action->handle($device, $uuid, $input), ['money_unit' => 'baisas']);
        } catch (QrChargeException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e->httpStatus);
        }
    }
}
