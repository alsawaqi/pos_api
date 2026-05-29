<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\BuildDeviceConfigAction;
use App\Http\Requests\Api\V1\Device\DeviceConfigDeltaRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Device config bundle — blueprint §11.4.
 *
 *  - GET /api/v1/device/config        full snapshot the terminal caches
 *  - GET /api/v1/device/config/delta  only what changed since `?since=`
 *
 * Both are authenticated by the `pos_device` guard; the resolved Device
 * supplies the company/branch scope. Envelope: { data, meta, errors }.
 */
class DeviceConfigController
{
    public function __construct(
        private readonly BuildDeviceConfigAction $builder,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $device = $this->device($request);

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        return response()->json($this->builder->handle($device, null) + ['errors' => []]);
    }

    public function delta(DeviceConfigDeltaRequest $request): JsonResponse
    {
        $device = $this->device($request);

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $since = Carbon::parse((string) $request->validated('since'));

        return response()->json($this->builder->handle($device, $since) + ['errors' => []]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
