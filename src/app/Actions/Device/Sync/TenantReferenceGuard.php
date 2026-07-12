<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use App\Models\PosStaff;
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
        if ($staffId !== null
            && ! PosStaff::withTrashed()->where('company_id', $device->company_id)->whereKey($staffId)->exists()) {
            throw new RuntimeException($message);
        }
    }
}
