<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use TypeError;
use ValueError;

/** An immutable receipt for retiring a proven same-bill cache, never an item submission. */
final class RecoverTableDraftAction
{
    public const POLICY = 'same_bill_recovery_v1';

    public function __construct(
        private readonly TableReadSnapshot $read,
        private readonly ReadTableDraftProofAction $proof,
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
        private readonly FrozenLegacyTableBill $frozen,
        private readonly AppendTableSessionEventAction $journal,
        private readonly QrChargeRecoveryGuard $recovery,
    ) {}

    public function preview(Device $device, int $tableId, array $input): array
    {
        $this->attended($device);
        $values = $this->values($input);

        return $this->read->handle(function () use ($device, $tableId, $values): array {
            $current = $this->current($device);
            $state = $this->state($current, $tableId, $values);
            $expires = now()->addMinutes(5)->timestamp;

            return ['recovery_policy' => self::POLICY, 'preview_token' => $expires.'.'.$this->signature($current, $state, $values, $expires),
                'expires_at' => now()->setTimestamp($expires)->toIso8601String(), 'proof' => $state['proof']];
        });
    }

    public function handle(Device $device, int $tableId, array $input): array
    {
        $this->attended($device);
        $values = $this->values($input, true);
        $requestHash = hash('sha256', json_encode([$tableId, $values], JSON_THROW_ON_ERROR));
        $payload = ['table_id' => $tableId, 'seating_key' => (string) Str::uuid(), 'queued_offline' => false];

        return $this->resolver->locked($device, $payload, 'draft_recovery', function (Device $current) use ($tableId, $values, $requestHash): array {
            // Replay wins over token expiry and bill/payment changes. The
            // original transaction already established archive permission.
            $previous = TableSessionEvent::query()->where('company_id', $current->company_id)
                ->where('branch_id', $current->branch_id)->where('device_id', $current->id)
                ->where('event_type', 'attached')->where('payload->action', 'same_bill_draft_recovered')
                ->where('payload->client_request_id', $values['client_request_id'])->first();
            if ($previous !== null) {
                if (! hash_equals((string) ($previous->payload['request_hash'] ?? ''), $requestHash)) {
                    throw $this->refusal('draft_recovery_request_conflict', 'This recovery request belongs to a different saved draft. Keep both records.');
                }

                return $previous->payload['result'] + ['event_id' => (int) $previous->id];
            }
            $parts = explode('.', $values['preview_token']);
            if (count($parts) === 2 && ctype_digit($parts[0]) && (int) $parts[0] <= now()->timestamp) {
                // Under the same locks as replay, an unapplied expired token
                // can never subsequently start. No other refusal releases an
                // immutable client intent, especially a lost HTTP response.
                throw new QrDineInException('draft_recovery_preview_stale', 409,
                    'This unapplied preview expired. Keep the original draft and review again.', finalNoWrite: true);
            }
            $state = $this->state($current, $tableId, $values);
            if (count($parts) !== 2 || ! ctype_digit($parts[0])
                || ! hash_equals($this->signature($current, $state, $values, (int) $parts[0]), $parts[1])) {
                throw $this->refusal('draft_recovery_preview_stale', 'The bill or its evidence changed. Keep the local draft and review again.');
            }
            // This existing accounting action owns original item ids and may
            // queue an accounting-only attached event; it never submits food.
            // Validate the signed PRE-baseline state, then re-prove the result.
            $this->baseline->handle($state['order']);
            $after = $this->state($current, $tableId, $values);
            $result = ['outcome' => 'draft_recovered', 'recovery_policy' => self::POLICY,
                'client_request_id' => $values['client_request_id'], 'local_snapshot_hash' => $values['local_snapshot_hash'],
                'order_uuid' => $values['order_uuid'], 'table_id' => $tableId,
                'table_session_uuid' => $after['seat']->uuid, 'preview_token' => $values['preview_token'],
                'archive_authorized' => true];
            $event = $this->journal->handle($after['seat'], 'attached', [
                'action' => 'same_bill_draft_recovered', 'client_request_id' => $values['client_request_id'],
                'request_hash' => $requestHash, 'kind' => $values['kind'],
                'local_snapshot_hash' => $values['local_snapshot_hash'],
                'acknowledged_event_ids' => array_column($after['proof']['acknowledged'], 'client_event_id'),
                'result' => $result,
            ], (int) $current->id);
            // Last write boundary: obtain THIS event's id, not a preceding
            // accounting baseline event. Resolver flush is now a no-op.
            $this->journal->flush();

            return $result + ['event_id' => (int) $event->id];
        });
    }

    public function values(array $input, bool $finalize = false): array
    {
        $rules = ['order_uuid' => ['required', 'uuid'], 'kind' => ['required', 'in:staff_rounds,legacy_hold'],
            'event_ids' => ['required_if:kind,staff_rounds', 'prohibited_if:kind,legacy_hold', 'array', 'min:1', 'max:100'],
            'event_ids.*' => ['required', 'uuid', 'distinct:ignore_case']];
        if ($finalize) {
            $rules += ['client_request_id' => ['required', 'uuid'], 'preview_token' => ['required', 'string', 'max:100'],
                'local_snapshot_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
        }
        $values = Validator::make($input, $rules)->validate();
        if (isset($values['event_ids'])) {
            sort($values['event_ids'], SORT_STRING);
        }

        return $values;
    }

    /** Caller owns one consistent read snapshot or the resolver's branch locks. */
    private function state(Device $device, int $tableId, array $values): array
    {
        try {
            $proof = $this->proof->recovery($device, $tableId, $values, true);
        } catch (TypeError|ValueError) {
            throw $this->refusal('draft_proof_evidence_changed', 'Stored bill evidence is malformed. Keep the local draft for separate review.');
        }
        $order = Order::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->where('uuid', $values['order_uuid'])->sole();
        $seat = TableSession::query()->whereKey($order->table_session_id)->sole();
        $table = Table::withTrashed()->whereKey($tableId)->sole();
        $credentials = QrSession::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->where('table_session_id', $seat->id)->orderBy('id')->get()
            ->map(static fn (QrSession $session): array => Arr::except($session->getRawOriginal(), ['last_seen_at', 'updated_at']))->all();
        $evidence = SyncEvent::query()->where('device_id', $device->id)
            ->whereIn('client_event_id', array_column($proof['acknowledged'], 'client_event_id'))
            ->orderBy('client_event_id')->get()->map->getRawOriginal()->all();

        return ['proof' => $proof, 'order' => $order, 'seat' => $seat,
            'signed' => [Arr::except($device->getRawOriginal(), ['last_seen_at', 'updated_at', 'last_ip', 'last_lat', 'last_lng', 'last_battery', 'app_version', 'pending_outbox_count', 'quarantined_count', 'outbox_reported_at', 'printer_status']), $table->getRawOriginal(), $seat->getRawOriginal(),
                $credentials, $this->frozen->snapshot($order), $evidence, $proof]];
    }

    private function signature(Device $device, array $state, array $values, int $expires): string
    {
        if (strlen((string) config('app.key')) < 32) {
            throw new QrDineInException('draft_recovery_unavailable', 503, 'Secure recovery signing is unavailable. Keep the draft.');
        }

        return hash_hmac('sha256', json_encode([self::POLICY, (int) $device->id, (int) $device->company_id,
            (int) $device->branch_id, $expires, $values['order_uuid'], $values['kind'], $values['event_ids'] ?? [],
            $state['signed']], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function current(Device $device): Device
    {
        $current = Device::withTrashed()->find($device->id);
        if ($current === null || (int) $current->company_id !== (int) $device->company_id
            || (int) $current->branch_id !== (int) $device->branch_id) {
            throw $this->refusal('device_not_attended', 'The device is no longer assigned to this branch.');
        }
        $this->attended($current);

        return $current;
    }

    private function attended(Device $device): void
    {
        if (! $this->recovery->isAttendedDevice($device) || $device->trashed()
            || $device->status !== 'active' || ! $device->isAssigned()) {
            throw $this->refusal('device_not_attended', 'An active assigned till or handheld is required.');
        }
    }

    private function refusal(string $code, string $message): QrDineInException
    {
        return new QrDineInException($code, 409, $message);
    }
}
