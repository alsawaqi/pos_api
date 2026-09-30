<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use App\Models\SyncEvent;
use App\Support\SyncReceiptFingerprint;
use Illuminate\Support\Facades\DB;

final class ReviewedSyncReplay
{
    /** Only a queued, audited original-identity review may supply this snapshot. */
    public function device(SyncEvent $event, int $reviewId): Device
    {
        $review = DB::table('pos_sync_event_reviews')->where('id', $reviewId)->lockForUpdate()->first();
        if ($review === null || $review->status !== 'queued'
            || (int) $review->sync_event_id !== (int) $event->id
            || (int) $review->company_id !== (int) $event->company_id
            || (int) $review->branch_id !== (int) $event->branch_id
            || ! hash_equals($review->fingerprint, SyncReceiptFingerprint::fingerprint((object) $event->getRawOriginal()))) {
            throw new \RuntimeException('The original-identity replay proof is missing or changed.');
        }
        $current = Device::withTrashed()->whereKey($event->device_id)->lockForUpdate()->firstOrFail();
        $snapshot = json_decode($review->device_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if ((int) $snapshot['company_id'] !== (int) $review->company_id
            || (int) $snapshot['branch_id'] !== (int) $review->branch_id) {
            throw new \RuntimeException('Review snapshot identity mismatch.');
        }
        // Never copy today's bank, donation, token, or tenant fields onto old work.
        $device = new Device;
        $device->setRawAttributes([
            'id' => $current->id, 'uuid' => $current->uuid, 'kiosk_id' => $current->kiosk_id,
            'serial_number' => $current->serial_number,
            'assigned_at' => $event->server_received_at->copy()->subSecond()->toDateTimeString(),
        ] + array_diff_key($snapshot, ['softpos_profile' => true]));
        $device->exists = true;
        $device->reviewedSoftposSnapshot = $snapshot['softpos_profile'] ?? [];

        return $device;
    }
}
