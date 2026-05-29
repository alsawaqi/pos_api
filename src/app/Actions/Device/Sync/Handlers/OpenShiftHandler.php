<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
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
 * time (§10.8) — a second open while one is live fails. Replays are deduped
 * upstream, so a re-pushed open never double-creates.
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

        $hasOpenShift = Shift::query()
            ->where('device_id', $device->getKey())
            ->where('status', Shift::STATUS_OPEN)
            ->exists();
        if ($hasOpenShift) {
            throw new RuntimeException('device already has an open shift');
        }

        $openedAt = isset($payload['opened_at']) ? Carbon::parse((string) $payload['opened_at']) : now();
        $openingBaisas = (int) ($payload['opening_cash_baisas'] ?? 0);

        return DB::transaction(function () use ($device, $uuid, $payload, $openedAt, $openingBaisas): array {
            $shift = Shift::create([
                'uuid' => $uuid,
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'staff_id' => $payload['staff_id'] ?? null,
                'opened_at' => $openedAt,
                'opening_cash' => Money::toOmr($openingBaisas),
                'status' => Shift::STATUS_OPEN,
            ]);

            return ['shift_id' => (int) $shift->id, 'shift_uuid' => $shift->uuid, 'status' => 'open'];
        });
    }
}
