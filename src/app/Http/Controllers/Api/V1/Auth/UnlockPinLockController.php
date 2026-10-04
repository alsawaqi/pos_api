<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\VerifyManagerPinAction;
use App\Http\Requests\Api\V1\Auth\VerifyManagerPinRequest;
use App\Models\Device;
use App\Support\Staff\PinLockout;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/device/auth/unlock-pin-lock — PHASE-1A D-7 (LAUNCH-P5 A4).
 *
 * A manager (any approver of this branch, {@see VerifyManagerPinAction})
 * types their PIN on a device whose staff LOGIN is locked: on success the
 * server clears THIS device's login-failure counter and lock, so the next
 * login is checked normally. The device calls this, then re-enables its
 * keypad. It is on the manager-pin bucket and is itself subject to the
 * manager-PIN lockout (D-5), so it cannot be brute-forced either.
 *
 * Success: 200 { ok: true, unlocked: true, staff: { id, name, position } }.
 * Wrong PIN: 401 invalid_pin. Manager PIN locked: 423 pin_locked.
 */
final class UnlockPinLockController
{
    public function __construct(
        private readonly VerifyManagerPinAction $verify,
        private readonly PinLockout $lockout,
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

        $this->lockout->clear($device, PinLockout::LOGIN);

        return response()->json([
            'ok' => true,
            'unlocked' => true,
            'staff' => [
                'id' => (int) $staff->id,
                'name' => $staff->name,
                'position' => $staff->position,
            ],
        ]);
    }
}
