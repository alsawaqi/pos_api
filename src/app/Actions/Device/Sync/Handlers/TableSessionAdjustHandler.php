<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\AdjustTableBillAction;
use App\Models\Device;
use App\Models\SyncEvent;

final class TableSessionAdjustHandler implements SyncEventHandler
{
    public function __construct(private readonly AdjustTableBillAction $action) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        try {
            return $this->action->handle($device, $event->payload_json, $event->client_timestamp, $event->server_received_at);
        } catch (QrDineInException $exception) {
            $p = $event->payload_json;

            return ['outcome' => 'refused', 'refusal_code' => $exception->codeName, 'message' => $exception->getMessage(),
                'seating_key' => $p['seating_key'], 'table_id' => (int) $p['table_id'], 'needs_review' => false,
                'client_request_id' => $p['client_request_id'], 'kind' => $p['adjustment']['kind'], 'mode' => $p['adjustment']['mode']];
        }
    }
}
