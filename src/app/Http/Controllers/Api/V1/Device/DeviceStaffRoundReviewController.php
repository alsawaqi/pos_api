<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Actions\Tables\RejectStaffRoundAction;
use App\Actions\Tablet\TabletOrderException;
use App\Actions\Tablet\TabletOrderStaffAction;
use App\Http\Middleware\RequireTabletStaff;
use App\Http\Requests\Api\V1\Device\StaffRoundReviewRequest;
use App\Kitchen\CloudIntake;
use App\Models\Device;
use App\Models\TabletOrder;
use App\Support\DeviceCapabilities;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceStaffRoundReviewController
{
    public function __construct(
        private readonly ConfirmStaffRoundAction $confirmRound,
        private readonly RejectStaffRoundAction $rejectRound,
        private readonly TabletOrderStaffAction $tablet,
    ) {}

    public function confirm(StaffRoundReviewRequest $request, string $uuid, int $roundId): JsonResponse
    {
        if (($tablet = $this->tabletRound($request, $uuid, $roundId, true)) !== null) {
            return $tablet;
        }
        try {
            return QrApiResponse::success(app(CloudIntake::class)->review($request, fn () => $this->confirmRound->handle($request->user(), $uuid, $roundId), 'approve', roundId: $roundId));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }

    public function reject(StaffRoundReviewRequest $request, string $uuid, int $roundId): JsonResponse
    {
        if (($tablet = $this->tabletRound($request, $uuid, $roundId, false)) !== null) {
            return $tablet;
        }
        try {
            return QrApiResponse::success(app(CloudIntake::class)->review($request, fn () => $this->rejectRound->handle($request->user(), $uuid, $roundId), 'reject', roundId: $roundId));
        } catch (QrDineInException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), $exception->httpStatus);
        }
    }

    /**
     * LAUNCH-P6 fix order 1 (F-6) — a customer tablet's round (it has a
     * pos_tablet_orders row of this branch) is reviewed only by a build that
     * declares `tablet-orders` (else 409 tablet_round_needs_update), for the
     * logged-in staff member of its X-Staff-Token (else 403 staff_unverified),
     * under the "Taken by" rule; null = not a tablet round (today's path).
     */
    private function tabletRound(StaffRoundReviewRequest $request, string $uuid, int $roundId, bool $confirm): ?JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $isTablet = TabletOrder::query()->where('round_id', $roundId)->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)->exists();
        if (! $isTablet) {
            return null;
        }
        if (! DeviceCapabilities::tabletOrders($request)) {
            return QrApiResponse::failure('tablet_round_needs_update', 'Update the app to handle tablet orders.', 409);
        }
        $staff = RequireTabletStaff::verify($request);
        if ($staff['failure'] !== null) {
            return RequireTabletStaff::refusal($staff['failure']);
        }
        try {
            return QrApiResponse::success(app(CloudIntake::class)->review($request, fn () => $this->tablet->reviewRound($device, (int) $staff['staff_id'], $uuid, $roundId, $confirm), $confirm ? 'approve' : 'reject', roundId: $roundId));
        } catch (TabletOrderException $exception) {
            return $exception->response();
        }
    }
}
