<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Device\StaffLoginAction;
use App\Http\Requests\Api\V1\Auth\StaffLoginRequest;
use App\Models\Branch;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/auth/pos/login — blueprint §11.1.
 *
 * A paired device authenticates the staff member operating it by PIN.
 * Returns the staff profile (id/name/position) the device stamps onto the
 * orders + shifts it pushes; the device caches it for offline re-login
 * (§9.1.3). Envelope: { data, errors }.
 */
class StaffPosLoginController
{
    public function __construct(
        private readonly StaffLoginAction $login,
        private readonly GeofenceGuard $geofence,
    ) {}

    public function __invoke(StaffLoginRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        // Geofence at sign-in (blueprint §9.4): a staff member may only log in
        // while the device is physically inside its branch fence. Mirrors the
        // fail-closed order.create guard — a fenced branch REQUIRES a GPS fix
        // inside the radius; an unfenced branch skips this entirely.
        $branch = Branch::find($device->branch_id);
        if ($branch !== null && $this->geofence->isFenced($branch)) {
            $lat = $request->validated('lat');
            $lng = $request->validated('lng');
            if ($lat === null || $lng === null) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'location_required', 'message' => 'Location is required to sign in at this branch. Enable location and try again.']],
                ], 422);
            }
            if (! $this->geofence->isWithin($branch, (float) $lat, (float) $lng)) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'outside_geofence', 'message' => 'You are outside the branch area. Move closer to the branch to sign in.']],
                ], 422);
            }
        }

        try {
            $staff = $this->login->login($device, (string) $request->validated('pin'));
        } catch (RuntimeException) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'invalid_pin', 'message' => 'Invalid PIN.']],
            ], 401);
        }

        return response()->json([
            'data' => [
                'staff' => [
                    'id' => (int) $staff->id,
                    'uuid' => $staff->uuid,
                    'name' => $staff->name,
                    'position' => $staff->position,
                    'branch_id' => (int) $staff->branch_id,
                ],
            ],
            'errors' => [],
        ]);
    }
}
