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
 * handler does the domain work in its own DB transaction and RETURNS the
 * result_json payload (e.g. the server order id) on success, or THROWS to
 * signal failure — the dispatcher records processed/failed accordingly.
 */
interface SyncEventHandler
{
    /**
     * @return array<string, mixed> the result_json recorded on the event + echoed in the ACK
     */
    public function handle(SyncEvent $event, Device $device): array;
}
