<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Events\DeviceSyncBroadcast;
use App\Models\Device;
use App\Models\PosStaff;
use App\Models\StaffMessage;
use App\Models\StaffMessageRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * P-G6 — staff-announcement read receipts ("sent is not the same as
 * seen").
 *
 *   POST /api/v1/device/messages/read   { staff_id, message_ids: [...] }
 *
 * The device calls this when the logged-in staff member opens the
 * messages sheet. Idempotent: a receipt is one row per (message, staff),
 * re-marking is a no-op. Each NEW receipt touch()es its message so the
 * updated read-set resurfaces in other devices' config deltas (the
 * customer-plate precedent) — that is how till B stops badging a message
 * staff X already read on till A. Messages arrive via the /device/config
 * staff_messages slice; this endpoint only reports reads.
 */
class DeviceMessagesController
{
    public function read(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'min:1'],
            'message_ids' => ['required', 'array', 'min:1', 'max:200'],
            'message_ids.*' => ['integer', 'min:1'],
        ]);

        // The reader must be an active staff member of this device's BRANCH
        // (the StaffLoginAction boundary, §5.4.2) — receipts are an audit
        // surface ("who saw what"), so a till must not be able to write them
        // on behalf of another branch's staff.
        $staffId = (int) $data['staff_id'];
        $staffExists = PosStaff::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->where('status', PosStaff::STATUS_ACTIVE)
            ->whereKey($staffId)
            ->exists();
        if (! $staffExists) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'unknown_staff', 'message' => 'Unknown staff member.']],
            ], 422);
        }

        // Only messages in THIS device's config audience can be marked
        // (mirrors the BuildDeviceConfigAction slice: company-wide, this
        // branch, or addressed to this very staff member). Anything else —
        // foreign companies, other branches' announcements, other staff's
        // private messages — is silently skipped (no information leak, and
        // the batch stays best-effort like the device expects).
        $messages = StaffMessage::query()
            ->where('company_id', $device->company_id)
            ->where(function ($q) use ($device, $staffId) {
                $q->where('target_type', StaffMessage::TARGET_COMPANY)
                    ->orWhere(fn ($qq) => $qq
                        ->where('target_type', StaffMessage::TARGET_BRANCH)
                        ->where('target_branch_id', $device->branch_id))
                    ->orWhere(fn ($qq) => $qq
                        ->where('target_type', StaffMessage::TARGET_STAFF)
                        ->where('target_staff_id', $staffId));
            })
            ->whereIn('id', array_map(intval(...), $data['message_ids']))
            ->get();

        $marked = 0;
        foreach ($messages as $message) {
            $read = StaffMessageRead::query()->firstOrCreate(
                [
                    'staff_message_id' => $message->id,
                    'staff_id' => $staffId,
                ],
                [
                    'device_id' => $device->id,
                    'read_at' => now(),
                ],
            );

            if ($read->wasRecentlyCreated) {
                $marked++;
                // Delta-freshness: the config slice filters messages by
                // updated_at — without this touch the new read-set would
                // never reach OTHER devices until something else edited
                // the message row.
                $message->touch();
            }
        }

        if ($marked > 0) {
            // Nudge the branch so other tills clear their badges promptly
            // (best-effort; the config delta heals on next sync anyway).
            try {
                event(new DeviceSyncBroadcast(
                    companyId: (int) $device->company_id,
                    branchId: (int) $device->branch_id,
                    eventId: 0,
                    type: 'message.read',
                    result: ['staff_id' => $staffId, 'marked' => $marked],
                ));
            } catch (Throwable) {
                // Advisory only.
            }
        }

        return response()->json([
            'data' => ['marked' => $marked],
            'errors' => [],
        ]);
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
