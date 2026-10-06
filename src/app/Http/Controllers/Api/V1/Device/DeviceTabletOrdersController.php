<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Tablet\TabletOrderEditAction;
use App\Actions\Tablet\TabletOrderException;
use App\Actions\Tablet\TabletOrderPresenter;
use App\Actions\Tablet\TabletOrderStaffAction;
use App\Http\Middleware\RequireTabletStaff;
use App\Http\Requests\Api\V1\Tablet\TabletEditLinesRequest;
use App\Models\Device;
use App\Support\QrApiResponse;
use App\Support\Staff\StaffToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * LAUNCH-P6 Part A item 4 — the staff side of the customer tablet, on a till
 * or a handheld with a logged-in staff member (EnsureAttendedDevice +
 * RequireTabletStaff): the list, take / take over, send to the kitchen and
 * the points request. Branch-scoped by the device token.
 */
final class DeviceTabletOrdersController
{
    public function __construct(
        private readonly TabletOrderStaffAction $action,
        private readonly TabletOrderPresenter $presenter,
    ) {}

    /** GET device/tablet-orders[?unpaid_only=1] */
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $rows = $this->action->rows($device, $request->boolean('unpaid_only'));

        return QrApiResponse::success(['orders' => $this->presenter->forStaff($rows)], [
            'generated_at' => now()->toIso8601String(), 'money_unit' => 'baisas',
        ]);
    }

    /** POST device/tablet-orders/{uuid}/take {take_over?: bool} */
    public function take(Request $request, string $uuid): JsonResponse
    {
        if (Validator::make($request->all(), ['take_over' => ['sometimes', 'boolean']])->fails()) {
            return QrApiResponse::failure('validation_failed', 'The request was invalid.', 422);
        }

        return $this->run(fn (): array => $this->action->take($this->device($request), $this->staff($request), $uuid,
            $request->boolean('take_over')));
    }

    /** POST device/tablet-orders/{uuid}/send-to-kitchen */
    public function send(Request $request, string $uuid): JsonResponse
    {
        return $this->run(fn (): array => $this->action->send($this->device($request), $this->staff($request), $uuid));
    }

    /** POST device/tablet-orders/{uuid}/redeem/approve {client_request_id, authorization} */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_request_id' => ['required', 'uuid'],
            'authorization' => ['sometimes', 'nullable', 'array'],
            'auth_v' => ['sometimes', 'integer'],
        ]);
        if ($validator->fails()) {
            return QrApiResponse::failure('validation_failed', 'The request was invalid.', 422);
        }
        $token = $request->header(StaffToken::HEADER);

        return $this->run(fn (): array => $this->action->approve($this->device($request), $this->staff($request), $uuid,
            $request->only(['client_request_id', 'authorization']), is_string($token) && $token !== '' ? $token : null));
    }

    /** PUT device/tablet-orders/{uuid}/lines {client_request_id, lines} — fix order 1 (F-8). */
    public function edit(TabletEditLinesRequest $request, string $uuid, TabletOrderEditAction $edit): JsonResponse
    {
        return $this->run(fn (): array => $edit->handle($this->device($request), $this->staff($request), $uuid, $request->validated()));
    }

    /** POST device/tablet-orders/{uuid}/redeem/reject */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        return $this->run(fn (): array => $this->action->reject($this->device($request), $this->staff($request), $uuid));
    }

    /** @param \Closure(): array<string, mixed> $operation */
    private function run(\Closure $operation): JsonResponse
    {
        try {
            return QrApiResponse::success($operation(), ['money_unit' => 'baisas']);
        } catch (TabletOrderException $exception) {
            return $exception->response();
        }
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    private function staff(Request $request): int
    {
        return (int) $request->attributes->get(RequireTabletStaff::STAFF);
    }
}
