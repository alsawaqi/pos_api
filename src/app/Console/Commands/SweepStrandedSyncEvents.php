<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Actions\Device\Sync\SyncEventDispatchLock;
use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers handled sync events whose request worker died before settlement.
 *
 * Eligibility deliberately uses server_received_at. A device timestamp may
 * be hours old for a healthy offline replay and therefore cannot distinguish
 * a live request from a stranded server worker.
 */
class SweepStrandedSyncEvents extends Command
{
    protected $signature = 'sync:sweep-stranded-events
        {--older-than=10 : Minimum age in minutes, measured from server receipt}
        {--limit=200 : Maximum events to dispatch per run}';

    protected $description = 'Re-dispatch handled sync events stranded in received state';

    public function __construct(
        private readonly SyncEventDispatcher $dispatcher,
        private readonly SyncEventDispatchLock $dispatchLock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sweepAfterId = $this->sweepAfterId();
        if ($sweepAfterId === null) {
            return self::INVALID;
        }

        $olderThan = $this->integerOption('older-than', minimum: 10);
        $limit = $this->integerOption('limit', minimum: 1);

        if ($olderThan === null || $limit === null) {
            return self::INVALID;
        }

        // Freeze one cutoff for the whole run. Ten minutes leaves a safety
        // margin above the API's five-minute upstream request timeout.
        $cutoff = now()->subMinutes($olderThan);
        $events = SyncEvent::query()
            ->where('id', '>', $sweepAfterId)
            ->where('ack_status', SyncEvent::STATUS_RECEIVED)
            ->where('server_received_at', '<=', $cutoff)
            ->whereIn('event_type', $this->dispatcher->handledEventTypes())
            ->lazyById(200);

        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $attempted = 0;

        foreach ($events as $event) {
            if ($attempted >= $limit) {
                break;
            }

            $lock = $this->dispatchLock->forEvent($event);
            if (! $lock->get()) {
                $skipped++;

                continue;
            }

            try {
                // Another recovery path may have settled or changed this row
                // after the candidate query. Re-check every invariant while
                // holding the same lock used by failed device retries.
                $event->refresh();
                if ($event->ack_status !== SyncEvent::STATUS_RECEIVED
                    || $event->server_received_at === null
                    || $event->server_received_at->isAfter($cutoff)
                    || ! $this->dispatcher->handles($event->event_type)) {
                    $skipped++;

                    continue;
                }

                // Soft-deleting a terminal must not strand work it had already
                // authenticated and durably submitted. A hard-deleted/null,
                // unassigned, or since-reassigned device cannot be attributed
                // safely, so leave that row untouched for operator recovery.
                $device = Device::withTrashed()->find($event->device_id);
                $unsafeReason = match (true) {
                    $device === null => 'missing_device',
                    ! $device->isAssigned() => 'unassigned_device',
                    $device->assigned_at !== null
                        && ! $device->assigned_at->lessThan($event->server_received_at) => 'device_reassigned_at_or_after_receipt',
                    default => null,
                };
                if ($unsafeReason !== null) {
                    Log::warning('Stranded sync event skipped because device attribution is unsafe', [
                        'sync_event_id' => (int) $event->getKey(),
                        'device_id' => $event->device_id !== null ? (int) $event->device_id : null,
                        'reason' => $unsafeReason,
                    ]);
                    $skipped++;

                    continue;
                }

                $attempted++;
                $this->dispatcher->dispatch($event, $device);
                $event->refresh();

                if ($event->ack_status === SyncEvent::STATUS_PROCESSED) {
                    $processed++;
                } elseif ($event->ack_status === SyncEvent::STATUS_FAILED) {
                    $failed++;
                } else {
                    $skipped++;
                }
            } finally {
                $lock->release();
            }
        }

        $summary = [
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'sweep_after_id' => $sweepAfterId,
            'cutoff' => $cutoff->toIso8601String(),
        ];
        if ($processed + $failed + $skipped > 0) {
            Log::info('Stranded sync event sweep completed', $summary);
        }

        $this->info("processed={$processed} failed={$failed} skipped={$skipped}");

        return self::SUCCESS;
    }

    private function sweepAfterId(): ?int
    {
        $configured = config('sync.stranded_sweep_after_id');
        $isCanonicalInteger = is_int($configured)
            || (is_string($configured) && preg_match('/^(0|[1-9][0-9]*)$/D', $configured) === 1);
        $value = $isCanonicalInteger
            ? filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;

        if ($value === false) {
            $this->error('SYNC_STRANDED_SWEEP_AFTER_ID must be configured as a nonnegative integer.');
            Log::error('Stranded sync event sweep aborted because its historical quarantine floor is missing or invalid', [
                'config_key' => 'sync.stranded_sweep_after_id',
            ]);

            return null;
        }

        return $value;
    }

    private function integerOption(string $name, int $minimum): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum],
        ]);

        if ($value === false) {
            $this->error("--{$name} must be an integer of at least {$minimum}.");

            return null;
        }

        return $value;
    }
}
