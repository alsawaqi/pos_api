<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\VerifyManagerPinAction;
use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\ApproverVerifier;
use App\Support\Staff\StaffBranches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device/approvers — LAUNCH-P5 (owner decisions 2 and 7).
 *
 * The approvers of the device's branch: ACTIVE staff of the company who work
 * at this branch (home or pos_staff_branches) and whose position holds
 * `approvals.give`. Each carries the offline verifier material — never K:
 *
 *   { data: { approvers: [ { staff_id, uuid, name, position,
 *                            salt, iterations, check } ], as_of }, errors: [] }
 *
 * salt / iterations / check are null for an approver with no verifier yet
 * (not logged in or approved online since the deploy): the device cannot
 * check that person offline and, online, calls verify-manager-pin instead.
 * Devices fetch it with the config, on resume and every 5 minutes online,
 * and replace their stored copy each time.
 */
final class DeviceApproversController
{
    public function __invoke(Request $request, VerifyManagerPinAction $managers): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        if (! $device->isAssigned()) {
            return response()->json(['data' => null, 'errors' => [[
                'code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.',
            ]]], 409);
        }

        $staff = StaffBranches::worksAt(PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereIn('position', $managers->approvalPositions((int) $device->company_id)), (int) $device->branch_id)
            ->orderBy('id')
            ->get();

        $approvers = $staff->map(static fn (PosStaff $s): array => [
            'staff_id' => (int) $s->id,
            'uuid' => (string) $s->uuid,
            'name' => (string) $s->name,
            'position' => (string) $s->position,
        ] + ApproverVerifier::material($s))->values()->all();

        return response()->json(['data' => [
            'approvers' => $approvers,
            'as_of' => now()->toIso8601String(),
        ], 'errors' => []]);
    }
}
