<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Tables\CloseStaffTableSessionAction;
use App\Models\Device;
use App\Models\SyncEvent;

/** Business verdicts are processed results, never retry-wedging exceptions. */
final class TableSessionCloseHandler implements SyncEventHandler
{
    public function __construct(private readonly CloseStaffTableSessionAction $action) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        return $this->action->handle($device, $event->payload_json, $event->client_timestamp, $event->server_received_at);
    }
}
