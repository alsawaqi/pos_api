<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/device/heartbeat — blueprint §11.4.
 *
 * Lightweight ping carrying GPS + battery so the platform can track
 * last-seen, geofence, and health. Authenticated by the pos_device
 * guard — $request->user() is the Device.
 *
 * Phase 8.5 adds geofence ENFORCEMENT (comparing last_lat/lng to the
 * branch fence + blocking order creation on breach); here we just
 * record the telemetry.
 */
class HeartbeatController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'battery' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /** @var Device $device */
        $device = $request->user();

        $device->update(array_filter([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'last_lat' => $validated['lat'] ?? null,
            'last_lng' => $validated['lng'] ?? null,
            'last_battery' => $validated['battery'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
        ], static fn ($v): bool => $v !== null));

        return response()->json([
            'data' => [
                'status' => 'ok',
                'server_time' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}
