<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\IngestSyncEventsAction;
use App\Http\Requests\Api\V1\Device\SyncPushRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/device/sync/push — blueprint §10.9 / §11.4.
 *
 * The inbound counterpart to 8.1's outbound config bundle: a paired
 * terminal pushes a batch of offline events and gets a per-event ACK back.
 * Idempotent on client_event_id (see IngestSyncEventsAction), so a 4-hour
 * offline backlog can be replayed and settles exactly once.
 *
 * Authenticated by the `pos_device` guard + throttled per-device. An
 * unassigned device (no company/branch) has no sales context, so it is
 * rejected with 409 just like the config endpoint. Envelope: { data, meta, errors }.
 */
class SyncPushController
{
    public function __construct(
        private readonly IngestSyncEventsAction $ingest,
    ) {}

    public function __invoke(SyncPushRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        /** @var list<array{client_event_id: string, event_type: string, client_timestamp: string, payload: array<string, mixed>}> $events */
        $events = $request->validated('events');

        return response()->json($this->ingest->handle($device, $events) + ['errors' => []]);
    }
}
