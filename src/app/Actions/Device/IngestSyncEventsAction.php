<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Actions\Device\Sync\SyncEventDispatchLock;
use App\Models\Device;
use App\Models\Shift;
use App\Models\SyncEvent;
use App\Support\Staff\AuthorizationGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 8.2 — ingests a batch of device sync events into the
 * pos_sync_events ledger, idempotently, and ACKs each one.
 *
 * The contract is EXACTLY-ONCE settlement keyed on (device_id, client_event_id):
 *  - First time we see an id → insert a `received` row, ACK { duplicate:false }.
 *  - Any later push of the same id → no second row, ACK { duplicate:true }
 *    re-returning the ORIGINAL row's id / status / result. EXCEPTION: a row
 *    left `failed` (its handler txn rolled back) is re-dispatched on re-push so
 *    a transient fault isn't a permanent silent no-settle.
 *
 * That is what lets a terminal that was offline for hours blindly re-push
 * its whole backlog (or push it twice) and have it settle once. Inserts are
 * independent (no outer transaction): one event never rolls back another, so
 * the per-event ACK the device gets is the durable truth for that event. The
 * composite (device_id, client_event_id) UNIQUE is the real guard — a concurrent batch that wins the insert
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
        private readonly SyncEventDispatchLock $dispatchLock,
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
            // LAUNCH-P5 fix order 1 (F2) — the first event carrying auth_v: 1
            // makes this device a P5 build for good (pos_devices.auth_v_seen_at).
            AuthorizationGate::observe($device, $event['payload'] ?? null);
            // LAUNCH-P5 (A8) — training mode never reaches the books: an event
            // carrying training: true is refused (permanently) and not stored.
            if (self::isTraining($event['payload'] ?? [])) {
                $results[] = ['client_event_id' => $event['client_event_id'], 'duplicate' => false,
                    'status' => SyncEvent::STATUS_FAILED, 'event_id' => null, 'server_received_at' => now()->toIso8601String(),
                    'processed_at' => null, 'result' => ['error' => 'Training-mode work is never sent to the server.',
                        'code' => 'training_refused', 'permanent' => true]];

                continue;
            }
            $existing = SyncEvent::query()->where('device_id', $device->getKey())
                ->where('client_event_id', $event['client_event_id'])->first();
            // An acknowledged settlement is terminal. Never replay it or turn
            // a historical processed ACK into a permanent refusal.
            if ($existing?->ack_status === SyncEvent::STATUS_PROCESSED) {
                // Fix order 1 L8 — a close under an OLD id for a shift the
                // portal has since re-opened (now open, higher reopen_count)
                // must not get the old Z back: the device rebuilds the id
                // with the current count and retries.
                $reopenCount = $this->reopenedSince($existing, $event, $device);
                if ($reopenCount !== null) {
                    $results[] = ['client_event_id' => $existing->client_event_id, 'duplicate' => true,
                        'status' => SyncEvent::STATUS_FAILED, 'event_id' => (int) $existing->getKey(),
                        'server_received_at' => $existing->server_received_at?->toIso8601String(), 'processed_at' => null,
                        'result' => ['error' => 'This shift was re-opened. Close it again under its current re-open count.',
                            'code' => 'shift_reopened', 'reopen_count' => $reopenCount]];
                    $duplicates++;

                    continue;
                }
                $ack = $this->ack($existing, duplicate: true);
                // Same device + same payload proves the original result, even
                // when its historical attribution is unknown (NULL). A receipt
                // stamped for another merchant/branch stays hidden after a move.
                $stampedElsewhere = $existing->company_id !== null && (
                    (int) $existing->company_id !== (int) $device->company_id
                    || (int) $existing->branch_id !== (int) $device->branch_id);
                if ($stampedElsewhere || (! $this->samePayload($existing, $event) && ! $this->sameShiftClose($existing, $event))) {
                    $ack['result'] = null;
                }
                $results[] = $ack;
                $duplicates++;

                continue;
            }
            $identity = $event['identity'] ?? null;
            $clientTimestamp = self::clientTimestamp($event['client_timestamp']);
            // Legacy APKs carry no identity tag. Allow at most five minutes
            // of clock skew, never a backlog preceding this credential epoch.
            $cutoff = $device->assignment_activated_at ?? $device->token_issued_at;
            $predatesCredential = $existing === null && $identity === null && $cutoff !== null
                && $clientTimestamp->lt(Carbon::parse($cutoff)->subMinutes(5));
            if ($predatesCredential || ($identity !== null && (
                (int) ($identity['company_id'] ?? 0) !== (int) $device->company_id
                || (int) ($identity['branch_id'] ?? 0) !== (int) $device->branch_id
                || ($identity['device_uuid'] ?? null) !== $device->uuid
            ))) {
                // Keep the original evidence without assigning it to today's
                // merchant. The audited review workflow can attribute it later;
                // the identity the device stamped on the sale is part of that
                // evidence (the row itself is stored at push time).
                $row = SyncEvent::firstOrCreate(
                    ['device_id' => $device->id, 'client_event_id' => $event['client_event_id']],
                    ['event_type' => $event['event_type'], 'payload_json' => $event['payload'],
                        'client_timestamp' => $clientTimestamp,
                        'server_received_at' => now(), 'ack_status' => SyncEvent::STATUS_NEEDS_REVIEW,
                        'result_json' => ['error' => 'identity_mismatch', 'code' => 'identity_mismatch', 'permanent' => true]
                            + ($identity === null ? [] : ['claimed_identity' => [
                                'company_id' => (int) ($identity['company_id'] ?? 0),
                                'branch_id' => (int) ($identity['branch_id'] ?? 0),
                                'device_uuid' => isset($identity['device_uuid']) ? (string) $identity['device_uuid'] : null,
                            ]])]
                );
                $results[] = $this->ack($row, duplicate: ! $row->wasRecentlyCreated);

                continue;
            }
            $existing = SyncEvent::query()
                ->where('device_id', $device->getKey())
                ->where('client_event_id', $event['client_event_id'])
                ->first();

            if ($existing !== null && (
                (int) $existing->company_id !== (int) $device->company_id
                || (int) $existing->branch_id !== (int) $device->branch_id
            )) {
                if (in_array($existing->ack_status, [SyncEvent::STATUS_FAILED, SyncEvent::STATUS_RECEIVED], true)) {
                    $existing->update(['ack_status' => SyncEvent::STATUS_NEEDS_REVIEW,
                        'result_json' => ['error' => 'identity_mismatch', 'permanent' => true]]);
                }
                $results[] = ['client_event_id' => $event['client_event_id'], 'duplicate' => true,
                    'status' => 'needs_review', 'result' => ['error' => 'identity_mismatch', 'permanent' => true]];
                $duplicates++;

                continue;
            }
            if ($existing !== null) {
                // A previously FAILED event is RETRIED, not swallowed. Serialize
                // retries across API workers: the ledger's UNIQUE constraint
                // prevents a second row, but cannot stop two requests that both
                // read this existing failed row from dispatching its side effect.
                if ($existing->ack_status === SyncEvent::STATUS_FAILED) {
                    $lock = $this->dispatchLock->forEvent($existing);

                    if (! $lock->get()) {
                        $existing->refresh();
                        $duplicates++;
                        $results[] = $this->ack($existing, duplicate: true);

                        continue;
                    }

                    try {
                        // The winner may have settled this row before this caller
                        // acquired the lock, so re-read before deciding to dispatch.
                        $existing->refresh();
                        if ($existing->ack_status === SyncEvent::STATUS_FAILED) {
                            $this->refreshFailedShiftClose($existing, $event);
                            $this->dispatcher->dispatch($existing, $device);
                            $existing->refresh();
                            $existing->ack_status === SyncEvent::STATUS_PROCESSED ? $accepted++ : $duplicates++;
                        } else {
                            $duplicates++;
                        }

                        $results[] = $this->ack($existing, duplicate: true);
                    } finally {
                        $lock->release();
                    }

                    continue;
                }

                $duplicates++;
                $results[] = $this->ack($existing, duplicate: true);

                continue;
            }

            try {
                $row = SyncEvent::create([
                    'client_event_id' => $event['client_event_id'],
                    'device_id' => $device->getKey(),
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                    'event_type' => $event['event_type'],
                    'payload_json' => $event['payload'],
                    'client_timestamp' => $clientTimestamp,
                    'server_received_at' => now(),
                    'ack_status' => SyncEvent::STATUS_RECEIVED,
                ]);

                // 8.3: process the event inline (order.create/pay/void) so
                // the ACK carries the settled state + server refs. The known
                // handler-less sync.noop stays `received`; duplicates never
                // reach here. Unknown types are rejected by SyncPushRequest.
                $this->dispatcher->dispatch($row, $device);

                $accepted++;
                $results[] = $this->ack($row, duplicate: false);
            } catch (UniqueConstraintViolationException) {
                // A concurrent push inserted this id between our SELECT and
                // INSERT — re-read the winner and ACK it as the duplicate.
                $row = SyncEvent::query()
                    ->where('device_id', $device->getKey())
                    ->where('client_event_id', $event['client_event_id'])
                    ->firstOrFail();

                $duplicates++;
                $ack = $this->ack($row, duplicate: true);
                if ($row->ack_status === SyncEvent::STATUS_PROCESSED && ! $this->samePayload($row, $event)) {
                    $ack['result'] = null;
                }
                $results[] = $ack;
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

    /** LAUNCH-P5 (A8) — `training: true` on an event payload (or its order). */
    public static function isTraining(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }
        $flag = $payload['training'] ?? ($payload['order']['training'] ?? null);

        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
     * LAUNCH-P5 (A5) — a P5 build closes a shift with a FIXED client_event_id
     * (UUID v5 of "shift-close:" + the shift uuid). A repeated close of the
     * same shift returns the original Z even when the device rebuilt the
     * payload (a new tap, a new closed_at).
     */
    private function sameShiftClose(SyncEvent $row, array $event): bool
    {
        return $row->event_type === 'shift.close' && $event['event_type'] === 'shift.close'
            && is_string($row->payload_json['shift_uuid'] ?? null)
            && ($row->payload_json['shift_uuid'] ?? null) === ($event['payload']['shift_uuid'] ?? null);
    }

    /**
     * Fix order 1 L8 — the shift's current reopen_count when this processed
     * close's shift is OPEN again with a higher count than the one that close
     * was made for (its result's reopen_count, 0 before it existed); null
     * otherwise (a closed shift keeps returning its Z).
     */
    private function reopenedSince(SyncEvent $row, array $event, Device $device): ?int
    {
        if (! $this->sameShiftClose($row, $event) || (int) $row->company_id !== (int) $device->company_id
            || (int) $row->branch_id !== (int) $device->branch_id) {
            return null;
        }
        $shift = Shift::query()->where('uuid', $row->payload_json['shift_uuid'])->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->first(['status', 'reopen_count']);
        $closedAt = (int) (((array) ($row->result_json ?? []))['reopen_count'] ?? 0);

        return $shift !== null && $shift->status === Shift::STATUS_OPEN && (int) $shift->reopen_count > $closedAt
            ? (int) $shift->reopen_count : null;
    }

    /**
     * LAUNCH-P5 (A5) — a shift.close that FAILED (e.g. unsynced_sales, or a
     * missing approval) had no effect, so its retry under the same fixed id
     * carries the device's current close (count, order list, authorization):
     * the stored payload is replaced before the re-dispatch. Other event
     * types keep their first payload.
     */
    private function refreshFailedShiftClose(SyncEvent $row, array $event): void
    {
        if (! $this->sameShiftClose($row, $event) || $this->samePayload($row, $event)) {
            return;
        }
        $row->update([
            'payload_json' => $event['payload'],
            'client_timestamp' => self::clientTimestamp($event['client_timestamp']),
        ]);
    }

    /**
     * LAUNCH-P3 fix order 1 K6 — the device's client_timestamp as a UTC
     * instant. A stamp with an offset ("…T15:00:00+04:00") keeps its offset
     * at parse and is converted to UTC, so the ledger column (UTC wall time,
     * no zone) stores 11:00:00, not the local 15:00:00.
     */
    private static function clientTimestamp(mixed $value): Carbon
    {
        return Carbon::parse((string) $value)->utc();
    }

    /**
     * Per-event ACK. `duplicate` is the idempotency signal; `status` is the
     * ledger settlement state (received now; processed/failed once 8.3 runs
     * handlers) — for a duplicate it is the ORIGINAL row's state.
     *
     * @return array<string, mixed>
     */
    private function samePayload(SyncEvent $row, array $event): bool
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $fingerprint = fn (string $type, array $payload): string => hash('sha256',
            json_encode([$type, $canonical($payload)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return hash_equals($fingerprint($row->event_type, $row->payload_json),
            $fingerprint($event['event_type'], $event['payload']));
    }

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
