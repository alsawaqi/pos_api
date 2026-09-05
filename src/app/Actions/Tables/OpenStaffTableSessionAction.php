<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\AllocateQrTempReferenceAction;
use App\Models\Device;
use App\Models\TableSession;
use App\Support\TableSessionOrigin;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class OpenStaffTableSessionAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly AllocateQrTempReferenceAction $references,
        private readonly AppendTableSessionEventAction $journal,
        private readonly JoinTableSessionAction $joins,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt): array
    {
        return $this->resolver->locked($device, $payload, 'open', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $clientAt, $receivedAt): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key']);
            if ($resolved['row'] !== null) {
                $row = $resolved['row'];
                $primary = $resolved['primary'];

                return $this->resolver->result(
                    in_array($row->status, [TableSession::STATUS_CLOSED, TableSession::STATUS_EXPIRED], true) ? 'already_closed' : 'replayed',
                    $payload, $row, $primary, $primary === null ? null : $orders->get((int) $primary->order_id),
                    $row->close_reason === TableSession::CLOSE_MERGED,
                );
            }
            $opened = $this->openLocked($device, $payload, $clientAt, $receivedAt, $seatings);
            $row = $opened['row'];
            $primary = $opened['primary'];
            $order = $orders->get((int) $primary->order_id);
            $result = $this->resolver->result($opened['outcome'], $payload, $row, $primary, $order, $opened['outcome'] === 'merged');
            if ($order === null && isset($payload['order_uuid'])) {
                // Device's local identity is acknowledged, never persisted as
                // a bill by an open. First-round creation owns the server bill.
                $result['order_uuid'] = $payload['order_uuid'];
            }
            if ($opened['outcome'] === 'opened' && ($payload['joined_table_ids'] ?? []) !== []) {
                $joined = $this->joins->joinLocked($device, $primary, $order, $seatings, $payload['joined_table_ids'], Carbon::parse($payload['opened_at']));
                $result += ['joined' => $joined['joined'], 'refused' => $joined['refused']];
            }

            return $result;
        });
    }

    /**
     * The round-before-open path uses this same occupied-table resolver.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, TableSession>  $seatings
     * @return array{outcome: string, row: TableSession, primary: TableSession}
     */
    public function openLocked(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, Collection $seatings): array
    {
        $live = $seatings->first(fn (TableSession $seat): bool => (int) $seat->table_id === (int) $payload['table_id'] && $this->resolver->isLive($seat));
        $openedAt = Carbon::parse($payload['opened_at']);
        $attributes = [
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'table_id' => (int) $payload['table_id'],
            'origin' => TableSessionOrigin::forDevice($device),
            'opened_by_device_id' => $device->id,
            'client_request_id' => $payload['seating_key'],
            'opened_at' => $openedAt,
            'expires_at' => $openedAt->copy()->addHours(6),
            'order_id' => null,
            'temp_reference' => null,
        ];
        $evidence = $this->resolver->clockEvidence($clientAt, $receivedAt);
        if ($live !== null) {
            $offline = $this->resolver->isOffline((bool) $payload['queued_offline'], $clientAt, $receivedAt);
            $outcome = $offline ? 'merged' : 'attached';
            $row = TableSession::query()->create($attributes + [
                'status' => TableSession::STATUS_MERGED,
                'merged_into_id' => $live->id,
                'closed_at' => now(),
                'close_reason' => $outcome,
            ]);
            $seatings->put((int) $row->id, $row);
            $primary = $live->merged_into_id === null ? $live : $seatings->get((int) $live->merged_into_id);
            if ($primary === null || ! $this->resolver->isLive($primary) || $primary->merged_into_id !== null) {
                throw new \RuntimeException('The joined winner must have a live real primary.');
            }
            $data = $evidence + ['winner_table_session_uuid' => $live->uuid, 'outcome' => $outcome, 'queued_offline' => $offline];
            $this->journal->handle($row, $outcome, $data, (int) $device->id);
            if ($offline) {
                $this->journal->handle($primary, 'needs_review', ['origin_table_session_uuid' => $row->uuid], (int) $device->id);
            }

            return ['outcome' => $outcome, 'row' => $row, 'primary' => $primary];
        }

        $row = TableSession::query()->create($attributes + ['status' => TableSession::STATUS_OPEN]);
        $row->update(['temp_reference' => $this->references->handle((int) $device->company_id, (int) $device->branch_id)]);
        $seatings->put((int) $row->id, $row);
        $this->journal->handle($row, 'opened', $evidence + ['outcome' => 'opened'], (int) $device->id);

        return ['outcome' => 'opened', 'row' => $row, 'primary' => $row];
    }
}
