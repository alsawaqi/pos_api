<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\ActivateDeviceAction;
use App\Http\Requests\Api\V1\Auth\ActivateDeviceRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/auth/device/activate.
 *
 * Single-code device activation. Returns the long-lived device_token plus the
 * device's kiosk_id + terminal_id (layer 1 stores both for the Soft POS /
 * Mosambee). Envelope { data, meta, errors }.
 */
class DeviceActivateController
{
    public function __construct(
        private readonly ActivateDeviceAction $activate,
    ) {}

    public function __invoke(ActivateDeviceRequest $request): JsonResponse
    {
        try {
            $device = $this->activate->handle((string) $request->validated('code'));
        } catch (RuntimeException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'activation_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'device_token' => $device->device_token,
                'device' => [
                    'uuid' => $device->uuid,
                    'company_id' => (int) $device->company_id,
                    'branch_id' => (int) $device->branch_id,
                    'kiosk_id' => $device->kiosk_id,
                    'terminal_id' => $device->terminal_id,
                    'terminal_pin' => $device->terminal_pin,
                    'name' => $device->name,
                ],
            ],
            'errors' => [],
        ], 200);
    }
}
