<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\PairDeviceAction;
use App\Http\Requests\Api\V1\Auth\PairDeviceRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/auth/device/pair — blueprint §11.1.
 *
 * Returns the long-lived device_token (Bearer credential) + a minimal
 * device descriptor. The FULL config bundle (§11.4 /device/config)
 * lands in Phase 8.1; for now pairing just establishes the token.
 *
 * Envelope shape per §11: { data, meta, errors }.
 */
class DevicePairController
{
    public function __construct(
        private readonly PairDeviceAction $pair,
    ) {}

    public function __invoke(PairDeviceRequest $request): JsonResponse
    {
        try {
            $device = $this->pair->handle(
                (string) $request->validated('kiosk_id'),
                (string) $request->validated('activation_token'),
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'pairing_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'device_token' => $device->device_token,
                'device' => [
                    'uuid' => $device->uuid,
                    'company_id' => (int) $device->company_id,
                    'branch_id' => (int) $device->branch_id,
                    'name' => $device->name,
                ],
            ],
            'errors' => [],
        ], 200);
    }
}
