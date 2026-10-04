<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\StaffBranches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device/staff-status — LAUNCH-P5 (M2: suspended or terminated
 * staff are logged out within 60 s while the device is online).
 *
 *   { data: { active_staff_ids: [int, ...], as_of }, errors: [] }
 *
 * The ids (only) of the ACTIVE staff of the company who work at the device's
 * branch (home or pos_staff_branches). A device polls it every 60 s while
 * online and logs out a logged-in person who is not in the list (their open
 * shift stays open).
 */
final class DeviceStaffStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        if (! $device->isAssigned()) {
            return response()->json(['data' => null, 'errors' => [[
                'code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.',
            ]]], 409);
        }

        $ids = StaffBranches::worksAt(PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE), (int) $device->branch_id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return response()->json(['data' => [
            'active_staff_ids' => $ids,
            'as_of' => now()->toIso8601String(),
        ], 'errors' => []]);
    }
}
