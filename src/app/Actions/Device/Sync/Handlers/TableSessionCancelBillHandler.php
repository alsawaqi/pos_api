<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\CancelTableBillAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Models\Device;
use App\Models\SyncEvent;

final class TableSessionCancelBillHandler implements SyncEventHandler
{
    public function __construct(private readonly CancelTableBillAction $action) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        try {
            return $this->action->handle($device, $event->payload_json, $event->client_timestamp, $event->server_received_at);
        } catch (QrDineInException $exception) {
            return EnsureLegacyTableBillBaselineAction::syncRefusal($exception, $event->payload_json)
                + $exception->details;
        }
    }
}
