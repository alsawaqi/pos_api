<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Device\VerifyManagerPinAction;
use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\StaffBranches;
use RuntimeException;

/**
 * Phase 4 — shared attribution-integrity guard for client-sent pos_staff ids.
 *
 * Every id a device stamps (an order's cashier, a comp approver, a shift
 * owner, a waste/count/expense recorder) came from that device's own
 * /device/config bundle, so a mismatch is a bug or a hostile device. The
 * device token is the tenant boundary — company_id is derived from it, never
 * the client — so a staff id must resolve WITHIN the device's company.
 *
 * withTrashed(): a since-terminated cashier's offline-queued event must still
 * settle, so soft-deleted staff still pass. A thrown RuntimeException stamps
 * the event `failed` (retryable) rather than silently nulling the audit trail.
 *
 * LAUNCH-P5 (A6): an event stamped with a staff member who is no longer
 * active (suspended, terminated or deleted) settles but is flagged
 * `staff_inactive:<id>`; "works at the device's branch" means the home branch
 * or a pos_staff_branches row.
 */
final class TenantReferenceGuard
{
    /**
     * Assert a client-sent staff id belongs to the device's own company. A
     * null id is a no-op (the attribution is simply absent). The caller
     * supplies the failure message so each event keeps its own wording.
     */
    public static function assertStaffInTenant(Device $device, ?int $staffId, string $message): void
    {
        if ($staffId === null) {
            return;
        }
        $staff = PosStaff::withTrashed()->where('company_id', $device->company_id)->whereKey($staffId)->first();
        if ($staff === null) {
            throw new RuntimeException($message);
        }
        if ($staff->trashed() || (string) $staff->status !== PosStaff::STATUS_ACTIVE) {
            $device->syncIntegrityFlags[] = 'staff_inactive:'.$staffId;
        }
    }

    public static function assertCashier(Device $device, ?int $staffId, string $message): void
    {
        self::assertStaffInTenant($device, $staffId, $message);
        if ($staffId === null) {
            return;
        }
        $staff = PosStaff::withTrashed()->findOrFail($staffId);
        $positions = app(VerifyManagerPinAction::class)->approvalPositions((int) $device->company_id);
        if (! StaffBranches::staffWorksAt($staffId, (int) $device->branch_id) && ! in_array($staff->position, $positions, true)) {
            $device->syncIntegrityFlags[] = 'staff_branch_changed:'.$staffId;
        }
    }

    public static function assertApprover(Device $device, ?int $staffId, string $message): void
    {
        self::assertStaffInTenant($device, $staffId, $message);
        if ($staffId === null) {
            return;
        }
        $positions = app(VerifyManagerPinAction::class)->approvalPositions((int) $device->company_id);
        if (! PosStaff::withTrashed()->whereKey($staffId)->whereIn('position', $positions)->exists()) {
            $device->syncIntegrityFlags[] = 'approver_position_changed:'.$staffId;
        }
        if (! StaffBranches::staffWorksAt($staffId, (int) $device->branch_id)) {
            $device->syncIntegrityFlags[] = 'approver_branch_changed:'.$staffId;
        }
    }
}
