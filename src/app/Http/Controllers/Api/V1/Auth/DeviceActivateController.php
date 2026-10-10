<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Device\ActivateDeviceAction;
use App\Actions\Device\DeviceActivationClaim;
use App\Actions\Device\DeviceActivationRefused;
use App\Actions\Device\ResolveDeviceSoftPos;
use App\Http\Requests\Api\V1\Auth\ActivateDeviceRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * POST /api/v1/auth/device/activate.
 *
 * Single-code device activation. Returns the long-lived device_token plus the
 * device's kiosk_id + terminal_id (layer 1 stores both for the Soft POS /
 * Mosambee) and its location_mode ("branch" | "any"). Envelope
 * { data, meta, errors }.
 *
 * Every refusal is HTTP 422 carrying a top-level { message, code } (the
 * LAUNCH-P1 app contract) AND the legacy errors[] envelope older APKs parse:
 *   activation_serial_missing | activation_device_mismatch |
 *   activation_app_missing    | activation_app_mismatch |
 *   activation_failed (bad/expired/used/revoked code, unassigned or blocked
 *   device). A code refused 5 times is revoked.
 */
class DeviceActivateController
{
    public function __construct(
        private readonly ActivateDeviceAction $activate,
    ) {}

    public function __invoke(ActivateDeviceRequest $request): JsonResponse
    {
        try {
            $device = $this->activate->handle(
                (string) $request->validated('code'),
                DeviceActivationClaim::fromInput($request->validated(), $request->ip()),
            );
        } catch (DeviceActivationRefused $e) {
            return self::refusal($e->reason, $e->getMessage());
        } catch (RuntimeException $e) {
            return self::refusal('activation_failed', $e->getMessage());
        }

        if ($device->device_type === 'kitchen_display') {
            return response()->json(['data' => ['device_token' => $device->plainTextToken, 'device' => ['uuid' => $device->uuid, 'company_id' => (int) $device->company_id, 'branch_id' => (int) $device->branch_id, 'device_type' => 'kitchen_display', 'name' => $device->name]], 'errors' => []]);
        }

        return response()->json([
            'data' => [
                'device_token' => $device->plainTextToken,
                'device' => [
                    'uuid' => $device->uuid,
                    'company_id' => (int) $device->company_id,
                    'branch_id' => (int) $device->branch_id,
                    'kiosk_id' => $device->kiosk_id,
                    ...app(ResolveDeviceSoftPos::class)->clientContract($device, $request->header('X-Mithqal-SoftPos-Capable') === '1'),
                    'name' => $device->name,
                    'location_mode' => $device->effectiveLocationMode(),
                ],
            ],
            'errors' => [],
        ], 200);
    }

    public static function refusal(string $code, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'data' => null,
            'errors' => [['code' => $code, 'message' => $message]],
        ], 422);
    }
}
