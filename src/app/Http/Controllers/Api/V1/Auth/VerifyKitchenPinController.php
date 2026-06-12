<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\VerifyKitchenPinAction;
use App\Http\Requests\Api\V1\Auth\VerifyKitchenPinRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/device/auth/verify-kitchen-pin — P-G1.6 the Kitchen
 * walk-up gate.
 *
 * When the logged-in staff member can't open the Kitchen screen
 * (position not in kitchen_positions), the device prompts for a kitchen
 * staff member's code and verifies it here. Success returns the kitchen
 * staff identity so the Kitchen session runs AS them — batches attribute
 * to the actual chef without a logout/login dance.
 *
 * Success: 200 { ok: true, staff: { id, name, position } }.
 * Bad/unknown/unauthorized PIN: 401 { data: null, errors: [{ code:
 * invalid_pin }] } — identical for "wrong PIN" and "right PIN, wrong
 * position" (the verify-manager-pin convention).
 */
class VerifyKitchenPinController
{
    public function __construct(
        private readonly VerifyKitchenPinAction $verify,
    ) {}

    public function __invoke(VerifyKitchenPinRequest $request): JsonResponse
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
