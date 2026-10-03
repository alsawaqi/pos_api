<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReplayReviewedSyncEvent extends Command
{
    protected $signature = 'sync:replay-reviewed {--review= : Audited review ID queued in pos_admin}';

    protected $description = 'Settle a reviewed historical receipt under its original merchant and financial snapshot';

    public function handle(SyncEventDispatcher $dispatcher): int
    {
        $review = DB::table('pos_sync_event_reviews')->where('id', $this->option('review'))->first();
        if ($review === null || $review->status !== 'queued') {
            $this->error('Queue an audited review in pos_admin first.');

            return self::FAILURE;
        }
        $event = SyncEvent::findOrFail($review->sync_event_id);
        if (! $dispatcher->handles($event->event_type)) {
            $this->error('No settlement handler exists for this event type.');

            return self::FAILURE;
        }
        $device = Device::withTrashed()->findOrFail($event->device_id);
        $dispatcher->dispatch($event, $device, (int) $review->id);
        $status = $event->fresh()->ack_status === SyncEvent::STATUS_PROCESSED ? 'processed' : 'failed';
        DB::transaction(function () use ($review, $event, $status) {
            $locked = DB::table('pos_sync_event_reviews')->where('id', $review->id)->lockForUpdate()->first();
            if ($locked->status !== 'queued') {
                return;
            }
            DB::table('pos_sync_event_reviews')->where('id', $review->id)->update(['status' => $status, 'updated_at' => now()]);
            DB::table('pos_audit_logs')->insert([
                'actor_user_id' => $review->actor_user_id, 'company_id' => $review->company_id,
                'branch_id' => $review->branch_id, 'event' => 'sync.history.replay_completed',
                'auditable_type' => SyncEvent::class, 'auditable_id' => $event->id,
                'metadata' => json_encode(['review_id' => $review->id, 'status' => $status]),
                'created_at' => now(), // pos_audit_logs has no updated_at
            ]);
        });
        $this->line('event='.$event->id.' status='.$status.' company='.$review->company_id.' branch='.$review->branch_id);

        return $status === 'processed' ? self::SUCCESS : self::FAILURE;
    }
}
