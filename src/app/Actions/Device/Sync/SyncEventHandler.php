<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use App\Models\SyncEvent;

/**
 * Phase 8.3 — contract for a domain processor of one sync event type.
 *
 * The {@see SyncEventDispatcher} routes a freshly-ingested {@see SyncEvent}
 * (ack_status=received) to the handler registered for its event_type. The
 * dispatcher owns the outer transaction that couples the handler's database
 * work to the processed event stamp. A handler returns result_json on success
 * or throws to roll back the whole effect before the dispatcher stamps failed.
 */
interface SyncEventHandler
{
    /**
     * @return array<string, mixed> the result_json recorded on the event + echoed in the ACK
     */
    public function handle(SyncEvent $event, Device $device): array;
}
