<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Device;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class MoveTableSessionAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, ?string $uuid = null): array
    {
        return $this->resolver->locked($device, $payload, 'move', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $row = $resolved['row'];
            $primary = $resolved['primary'];
            if ($row === null) {
                return $this->resolver->result('unknown_seating', $payload);
            }
            $order = $primary === null ? null : $orders->get((int) $primary->order_id);
            if (! $this->resolver->isLive($resolved['winner']) || ! $this->resolver->isLive($primary)) {
                return $this->resolver->result('stale_generation', $payload, $row, $primary, $order);
            }
            $targetId = (int) $payload['to_table_id'];
            if ((int) $primary->table_id === $targetId) {
                return $this->resolver->result('replayed', $payload, $row, $primary, $order);
            }
            if ((int) $primary->table_id !== (int) $payload['from_table_id']) {
                return $this->resolver->result('stale_generation', $payload, $row, $primary, $order);
            }
            $occupied = $seatings->first(fn (TableSession $seat): bool => (int) $seat->table_id === $targetId && $this->resolver->isLive($seat));
            if ($occupied !== null) {
                return $this->resolver->result('target_occupied', $payload, $row, $primary, $order);
            }
            $fromId = (int) $primary->table_id;
            if ($order !== null) {
                $order->update(['table_id' => $targetId]);
                DB::table('pos_order_tables')->where('order_id', $order->id)->where('table_id', $fromId)
                    ->update(['table_id' => $targetId]);
            }
            foreach ($sessions as $session) {
                if ((int) $session->table_session_id === (int) $primary->id) {
                    $changes = ['table_id' => $targetId];
                    if ($session->origin === 'table_card') {
                        $changes['table_qr_token_hash'] = hash('sha256', (string) $tables->get($targetId)->qr_token);
                    }
                    $session->update($changes);
                }
            }
            $primary->update(['table_id' => $targetId]);
            // Joined physical tables remain where they are; they still share
            // the same primary/bill. No priced child is purged or replaced.
            $data = ['from_table_id' => $fromId, 'to_table_id' => $targetId, 'moved_at' => $payload['moved_at']];
            $this->journal->handle($primary, 'moved', $data, (int) $device->id);
            foreach ($seatings as $joined) {
                if ((int) $joined->merged_into_id === (int) $primary->id && $this->resolver->isLive($joined)) {
                    $this->journal->handle($joined, 'moved', $data + ['primary_table_session_uuid' => $primary->uuid], (int) $device->id);
                }
            }

            return $this->resolver->result('moved', $payload, $row, $primary, $order) + ['from_table_id' => $fromId, 'to_table_id' => $targetId];
        });
    }
}
