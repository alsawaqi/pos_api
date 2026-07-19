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
 *
 * HH-2 — pass ?staff_id=N to look up the STAFF's open shift at this branch
 * first, whichever device opened it: the same person opens one shift a day
 * and both pos_machine and pos_handheld share it. Falls back to this device's
 * own open shift (the legacy drawer-inheritance model) when the staff has
 * none. Omitting staff_id keeps the pure device-keyed behavior for deployed
 * clients.
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

        $staffId = (int) $request->query('staff_id', '0');

        $shift = null;
        if ($staffId > 0) {
            // Only SHARED shifts travel across devices — a legacy per-device
            // shift the same staff opened on an old build stays that device's
            // drawer and must not be adopted elsewhere (its close attributes
            // by device only, so foreign sales would escape its Z).
            $shift = Shift::query()
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->where('staff_id', $staffId)
                ->where('status', Shift::STATUS_OPEN)
                ->where('is_shared', true)
                ->latest('opened_at')
                ->first();
        }
        $shift ??= Shift::query()
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
                    'device_id' => $shift->device_id !== null ? (int) $shift->device_id : null,
                ],
            ],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }
}
