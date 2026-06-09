<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use App\Models\Shift;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device/shift/current — the device's currently-open shift, or null.
 *
 * Lets a device RECOVER from a local↔server shift desync. The server enforces
 * one open shift per device, so if the device lost its local open-shift record
 * (re-pair, cleared storage, a second device at the branch) but the server still
 * has one open, a fresh `shift.open` just fails with "device already has an open
 * shift" and the cashier is stuck on the open-shift screen. The device reads the
 * existing shift here and ADOPTS it instead. Money is integer baisas.
 */
class DeviceShiftController
{
    public function current(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        $shift = Shift::query()
            ->where('device_id', $device->getKey())
            ->where('status', Shift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        return response()->json([
            'data' => [
                'shift' => $shift === null ? null : [
                    'uuid' => $shift->uuid,
                    'opening_cash_baisas' => Money::toBaisas($shift->opening_cash),
                    'opened_at' => $shift->opened_at?->toIso8601String(),
                    'staff_id' => $shift->staff_id !== null ? (int) $shift->staff_id : null,
                ],
            ],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }
}
