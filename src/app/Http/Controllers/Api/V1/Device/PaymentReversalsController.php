<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Payments\ApplyPaymentReversalResultAction;
use App\Actions\Payments\PaymentReversalOptionsAction;
use App\Actions\Payments\ReservePaymentReversalAction;
use App\Actions\Payments\ReversalContract;
use App\Actions\Payments\ReversalException;
use App\Models\PaymentReversal;
use App\Support\QrApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentReversalsController
{
    public function reserve(Request $request, string $paymentUuid, ReservePaymentReversalAction $action): JsonResponse
    {
        $payload = $request->validate([
            'kind' => ['required', 'in:void,refund'], 'manager_pin' => ['nullable', 'string', 'max:32'],
            'client_request_id' => ['required', 'uuid'], 'reason_code' => ['required', 'string', 'max:32'],
            'reason_note' => ['nullable', 'string', 'max:255'], 'void_reason_id' => ['nullable', 'integer', 'min:1'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.order_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999999.999'],
            'custom_amount_baisas' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ]);

        return $this->respond(fn () => $action->handle($request->user(), $paymentUuid, $payload));
    }

    public function result(Request $request, string $uuid, ApplyPaymentReversalResultAction $action): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['required', 'in:approved,declined,uncertain,cancelled'],
            'client_request_id' => ['required', 'uuid'], 'response_code' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:255'], 'receipt_json' => ['nullable', 'array'],
            'reversal_transaction_id' => ['nullable', 'string', 'max:64'],
            'rrn' => ['nullable', 'string', 'max:32'], 'auth_code' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->respond(fn () => $action->handle($uuid, $payload, (int) $request->user()->id));
    }

    public function index(Request $request, ReservePaymentReversalAction $guard): JsonResponse
    {
        // A blocked attended device still needs recovery; only new reserves
        // require an unblocked card terminal.
        try {
            $guard->assertAttended($request->user());
        } catch (ReversalException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
        $statuses = explode(',', (string) $request->query('status', 'pending,uncertain'));
        if (array_diff($statuses, ['pending', 'uncertain']) !== []) {
            return QrApiResponse::failure('invalid_reversal_status', 'Only pending and uncertain reversals are available here.', 422);
        }

        return QrApiResponse::success(['reversals' => PaymentReversal::query()
            ->where('device_id', $request->user()->id)
            ->where('company_id', $request->user()->company_id)
            ->where('branch_id', $request->user()->branch_id)->whereIn('status', $statuses)
            ->orderBy('id')->get()->map(fn ($row) => ReversalContract::present($row))->all()]);
    }

    public function payments(Request $request, string $uuid, PaymentReversalOptionsAction $action): JsonResponse
    {
        return $this->respond(fn () => $action->handle($request->user(), $uuid));
    }

    private function respond(Closure $callback): JsonResponse
    {
        try {
            return QrApiResponse::success($callback());
        } catch (ReversalException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }
}
