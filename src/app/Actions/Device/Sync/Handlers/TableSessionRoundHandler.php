<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Kitchen\DomainLinkage;
use App\Models\Device;
use App\Models\SyncEvent;

/** Business verdicts are processed results, never retry-wedging exceptions. */
final class TableSessionRoundHandler implements SyncEventHandler
{
    public function __construct(private readonly AppendStaffRoundAction $action) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        try {
            $result = $this->action->handle($device, $event->payload_json, $event->client_timestamp, $event->server_received_at);
            DomainLinkage::forEvent($event, $device, $result);

            return $result;
        } catch (QrDineInException $exception) {
            // The action transaction rolled back, including any alias writes.
            return EnsureLegacyTableBillBaselineAction::syncRefusal($exception, $event->payload_json);
        }
    }
}
