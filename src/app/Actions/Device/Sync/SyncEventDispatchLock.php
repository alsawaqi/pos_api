<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\SyncEvent;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes server-side re-dispatch of one durable sync event.
 *
 * The ledger's unique key prevents duplicate rows, but it cannot prevent two
 * workers from running a non-idempotent handler for the same existing row.
 * Both failed device retries and the stranded-event sweep use this lock so
 * they cannot cross the received -> failed boundary under different locks.
 */
final class SyncEventDispatchLock
{
    private const PREFIX = 'device-sync:dispatch:';

    private const SECONDS = 600;

    public function forEvent(SyncEvent $event): Lock
    {
        return Cache::lock(self::PREFIX.$event->getKey(), self::SECONDS);
    }
}
