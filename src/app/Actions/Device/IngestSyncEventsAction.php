<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 8.2 — ingests a batch of device sync events into the
 * pos_sync_events ledger, idempotently, and ACKs each one.
 *
 * The contract is EXACTLY-ONCE settlement keyed on client_event_id:
 *  - First time we see an id → insert a `received` row, ACK { duplicate:false }.
 *  - Any later push of the same id → no second row, ACK { duplicate:true }
 *    re-returning the ORIGINAL row's id / status / result.
 *
 * That is what lets a terminal that was offline for hours blindly re-push
 * its whole backlog (or push it twice) and have it settle once. Inserts are
 * independent (no outer transaction): one event never rolls back another, so
 * the per-event ACK the device gets is the durable truth for that event. The
 * UNIQUE column is the real guard — a concurrent batch that wins the insert
 * race surfaces here as a UniqueConstraintViolationException, which we treat
 * as the duplicate it is.
 *
 * 8.2 stops at `received`. Dispatching each received event to its domain
 * handler (order/payment/shift…) and stamping it processed/failed with a
 * result_json is 8.3+; the ACK already exposes those fields so the wire
 * contract does not change when that lands.
 */
class IngestSyncEventsAction
{
    public function __construct(
        private readonly SyncEventDispatcher $dispatcher,
    ) {}

    /**
     * @param  list<array{client_event_id: string, event_type: string, client_timestamp: string, payload: array<string, mixed>}>  $events
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Device $device, array $events): array
    {
        $results = [];
        $accepted = 0;
        $duplicates = 0;

        foreach ($events as $event) {
            $existing = SyncEvent::query()
                ->where('client_event_id', $event['client_event_id'])
                ->first();

            if ($existing !== null) {
                $duplicates++;
                $results[] = $this->ack($existing, duplicate: true);

                continue;
            }

            try {
                $row = SyncEvent::create([
                    'client_event_id' => $event['client_event_id'],
                    'device_id' => $device->getKey(),
                    'event_type' => $event['event_type'],
                    'payload_json' => $event['payload'],
                    'client_timestamp' => Carbon::parse($event['client_timestamp']),
                    'server_received_at' => now(),
                    'ack_status' => SyncEvent::STATUS_RECEIVED,
                ]);

                // 8.3: process the event inline (order.create/pay/void) so
                // the ACK carries the settled state + server refs. Unknown
                // types stay `received`; duplicates never reach here.
                $this->dispatcher->dispatch($row, $device);

                $accepted++;
                $results[] = $this->ack($row, duplicate: false);
            } catch (UniqueConstraintViolationException) {
                // A concurrent push inserted this id between our SELECT and
                // INSERT — re-read the winner and ACK it as the duplicate.
                $row = SyncEvent::query()
                    ->where('client_event_id', $event['client_event_id'])
                    ->firstOrFail();

                $duplicates++;
                $results[] = $this->ack($row, duplicate: true);
            }
        }

        return [
            'data' => [
                'results' => $results,
                'summary' => [
                    'total' => count($events),
                    'accepted' => $accepted,
                    'duplicates' => $duplicates,
                ],
            ],
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'device_id' => (int) $device->getKey(),
            ],
        ];
    }

    /**
     * Per-event ACK. `duplicate` is the idempotency signal; `status` is the
     * ledger settlement state (received now; processed/failed once 8.3 runs
     * handlers) — for a duplicate it is the ORIGINAL row's state.
     *
     * @return array<string, mixed>
     */
    private function ack(SyncEvent $row, bool $duplicate): array
    {
        return [
            'client_event_id' => $row->client_event_id,
            'duplicate' => $duplicate,
            'status' => $row->ack_status,
            'event_id' => (int) $row->getKey(),
            'server_received_at' => $row->server_received_at?->toIso8601String(),
            'processed_at' => $row->processed_at?->toIso8601String(),
            'result' => $row->result_json,
        ];
    }
}
