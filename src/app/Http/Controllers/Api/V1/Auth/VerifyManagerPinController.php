<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\VerifyManagerPinAction;
use App\Http\Requests\Api\V1\Auth\VerifyManagerPinRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/device/auth/verify-manager-pin — P-F1 manager PIN fallback.
 *
 * The device gates sensitive actions (comps, cancellations, gifts) behind a
 * manager fingerprint; this endpoint is the PIN fallback. It verifies that
 * the submitted PIN belongs to an ACTIVE staff member of the device's
 * company whose position is in the company's `manager_approval_positions`
 * policy (default managers-only) — ANY such staff member, not necessarily
 * the logged-in operator. Returns the approver's identity so the device can
 * stamp who authorized the action.
 *
 * Success: 200 { ok: true, staff: { id, name, position } }.
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

        return response()->json([
            'ok' => true,
            'staff' => [
                'id' => (int) $staff->id,
                'name' => $staff->name,
                'position' => $staff->position,
            ],
        ]);
    }
}
