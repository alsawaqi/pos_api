<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\VerifyManagerPinAction;
use App\Http\Requests\Api\V1\Auth\VerifyManagerPinRequest;
use App\Models\Device;
use App\Support\Staff\ApproverVerifier;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/device/auth/verify-manager-pin — the online approver check.
 *
 * LAUNCH-P5: it verifies that the submitted PIN belongs to an ACTIVE staff
 * member of the device's company who works at the DEVICE'S BRANCH (home or
 * pos_staff_branches) and whose position holds `approvals.give` — ANY such
 * staff member, not necessarily the logged-in operator. It lazily makes the
 * approver's offline verifier and returns it with the approver, so the
 * device can make the approval proof. Locked out after 5 consecutive wrong
 * PINs (423 pin_locked, PHASE-1A D-5).
 *
 * Success: 200 { ok: true, staff: { id, staff_id, uuid, name, position,
 * salt, iterations, check } } (salt/iterations/check null only if the
 * verifier could not be made).
 * Bad/unknown/unauthorized PIN: 401 { data: null, errors: [{ code:
 * invalid_pin }] } — the StaffPosLoginController error style, deliberately
 * identical for "wrong PIN" and "right PIN, wrong position" so the response
 * never reveals whether a PIN exists.
 */
class VerifyManagerPinController
{
    public function __construct(
        private readonly VerifyManagerPinAction $verify,
    ) {}

    public function __invoke(VerifyManagerPinRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        try {
            $staff = $this->verify->verify($device, (string) $request->validated('pin'));
        } catch (RuntimeException) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'invalid_pin', 'message' => 'Invalid PIN.']],
            ], 401);
        }

        // LAUNCH-P5 — make the verifier while the PIN is in hand (only when
        // the approver has none yet), then return the approver as a
        // /device/approvers entry (staff_id, uuid, name, position, salt,
        // iterations, check; never K) plus the legacy `id`, so the device can
        // make the approval proof right away.
        ApproverVerifier::ensureFor($staff, (string) $request->validated('pin'));

        return response()->json([
            'ok' => true,
            'staff' => [
                'id' => (int) $staff->id,
                'staff_id' => (int) $staff->id,
                'uuid' => (string) $staff->uuid,
                'name' => $staff->name,
                'position' => $staff->position,
            ] + ApproverVerifier::material($staff),
        ]);
    }
}
