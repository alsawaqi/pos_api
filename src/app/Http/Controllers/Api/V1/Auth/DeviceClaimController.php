<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\ClaimDeviceAction;
use App\Http\Requests\Api\V1\Auth\ClaimDeviceRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/auth/device/claim.
 *
 * Returns the long-lived device_token (Bearer credential) + a device
 * descriptor that includes the terminal_id (the app forwards it to the Soft
 * POS / Mosambee APK for card payments). Envelope { data, meta, errors }.
 */
class DeviceClaimController
{
    public function __construct(
        private readonly ClaimDeviceAction $claim,
    ) {}

    public function __invoke(ClaimDeviceRequest $request): JsonResponse
    {
        try {
            $device = $this->claim->handle((string) $request->validated('terminal_id'));
        } catch (RuntimeException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'claim_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'device_token' => $device->device_token,
                'device' => [
                    'uuid' => $device->uuid,
                    'company_id' => (int) $device->company_id,
                    'branch_id' => (int) $device->branch_id,
                    'terminal_id' => $device->terminal_id,
                    'name' => $device->name,
                ],
            ],
            'errors' => [],
        ], 200);
    }
}
