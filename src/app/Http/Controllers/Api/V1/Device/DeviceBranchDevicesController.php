<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device/branch-devices — the OTHER devices at this device's
 * branch, for the order-transfer picker.
 *
 * A device can only ever see (and transfer to) assigned, in-service devices in
 * its own company + branch; itself and any blocked/retired terminal are
 * excluded. `last_seen_at` lets the UI show an online dot (heartbeat-driven),
 * but a device is transfer-eligible regardless of when it last checked in —
 * the transfer is durable server-side and the target picks it up whenever it
 * next polls its inbox.
 */
class DeviceBranchDevicesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        $devices = Device::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->whereKeyNot($device->getKey())
            ->whereNotIn('status', ['blocked', 'inactive'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'devices' => $devices->map(fn (Device $d): array => [
                    'id' => (int) $d->getKey(),
                    'uuid' => $d->uuid,
                    'name' => $d->name ?? ('Device #'.$d->getKey()),
                    'device_type' => $d->device_type,
                    'terminal_id' => $d->terminal_id,
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                ])->all(),
            ],
            'meta' => ['count' => $devices->count()],
            'errors' => [],
        ]);
    }
}
