<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\Device;
use App\Models\Shift;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8.5 — processes a `shift.open` sync event into a pos_shifts row.
 *
 * Company/branch/device come from the authenticated device; the payload
 * carries the device-generated shift uuid, the opening cash float, and
 * (optionally) the staff member. A device may hold only ONE open shift at a
 * time (§10.8) — a second open while one is live fails.
 *
 * HH-2 — payload `shared_shift: true` (sent ONLY by HH-2-aware builds) opts
 * into the STAFF-shared model: one open shared shift per staff per branch,
 * adopted by every terminal they log into; a second shared open fails with a
 * distinct error and the client adopts via GET /device/shift/current.
 * Deployed field builds never send the flag, so they keep the pure
 * per-device drawer semantics unchanged — the two generations coexist.
 * Replays are deduped upstream, so a re-pushed open never double-creates.
 */
class OpenShiftHandler implements SyncEventHandler
{
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $uuid = $payload['uuid'] ?? null;
        if (! is_string($uuid)) {
            throw new RuntimeException('invalid shift.open payload: uuid required');
        }
        $staffId = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
        if ($staffId !== null && $staffId <= 0) {
            $staffId = null;
        }
        // Phase 4 — the shift's drawer owner (pos_shifts.staff_id) also
        // partitions the shared-shift "one open shift per staff" invariant, so
        // a foreign/bogus id both mislabels the drawer and lets a device open
        // shared shifts under arbitrary staff ids. Guard before the transaction.
        TenantReferenceGuard::assertStaffInTenant($device, $staffId, 'shift references a staff member outside the device tenant');

        $isShared = ($payload['shared_shift'] ?? false) === true && $staffId !== null;

        $openedAt = isset($payload['opened_at']) ? Carbon::parse((string) $payload['opened_at']) : now();
        $openingBaisas = (int) ($payload['opening_cash_baisas'] ?? 0);

        // Guards live inside the write transaction to shrink the check-then-
        // create window; like the original device guard, full serialization
        // is deliberately not DB-enforced (see the pos_shifts migration note).
        return DB::transaction(function () use ($device, $uuid, $staffId, $isShared, $openedAt, $openingBaisas): array {
            $hasOpenShift = Shift::query()
                ->where('device_id', $device->getKey())
                ->where('status', Shift::STATUS_OPEN)
                ->exists();
            if ($hasOpenShift) {
                throw new RuntimeException('device already has an open shift');
            }

            if ($isShared) {
                $staffHasOpenShift = Shift::query()
                    ->where('company_id', $device->company_id)
                    ->where('branch_id', $device->branch_id)
                    ->where('staff_id', $staffId)
                    ->where('status', Shift::STATUS_OPEN)
                    ->where('is_shared', true)
                    ->exists();
                if ($staffHasOpenShift) {
                    throw new RuntimeException('staff already has an open shift');
                }
            }

            $shift = Shift::create([
                'uuid' => $uuid,
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'staff_id' => $staffId,
                'opened_at' => $openedAt,
                'opening_cash' => Money::toOmr($openingBaisas),
                'status' => Shift::STATUS_OPEN,
                'is_shared' => $isShared,
            ]);

            return ['shift_id' => (int) $shift->id, 'shift_uuid' => $shift->uuid, 'status' => 'open'];
        });
    }
}
