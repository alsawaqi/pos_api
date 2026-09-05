<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Device;
use App\Models\Order;
use App\Models\TableSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class JoinTableSessionAction
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
        return $this->resolver->locked($device, $payload, 'join', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $row = $resolved['row'];
            $primary = $resolved['primary'];
            $order = $primary === null ? null : $orders->get((int) $primary->order_id);
            if ($row === null) {
                return $this->resolver->result('unknown_seating', $payload);
            }
            if (! $this->resolver->isLive($resolved['winner']) || ! $this->resolver->isLive($primary)) {
                return $this->resolver->result('stale_generation', $payload, $row, $primary, $order);
            }
            $joined = $this->joinLocked($device, $primary, $order, $seatings, $payload['join_table_ids'], Carbon::parse($payload['joined_at']));

            return $this->resolver->result($joined['created'] === 0 && $joined['refused'] === [] ? 'replayed' : 'joined', $payload, $row, $primary, $order)
                + ['joined' => $joined['joined'], 'refused' => $joined['refused']];
        });
    }

    /**
     * All table/order/session/seating locks are already owned by the resolver.
     * A joined member keeps its physical table and shares its primary's bill.
     *
     * @param  Collection<int, TableSession>  $seatings
     * @param  list<int>  $tableIds
     * @return array{joined: list<int>, refused: list<int>, created: int}
     */
    public function joinLocked(Device $device, TableSession $primary, ?Order $order, Collection $seatings, array $tableIds, CarbonInterface $at): array
    {
        $joined = [];
        $refused = [];
        $created = 0;
        foreach (array_unique(array_map('intval', $tableIds)) as $tableId) {
            if ($tableId === (int) $primary->table_id) {
                $joined[] = $tableId;

                continue;
            }
            $live = $seatings->first(fn (TableSession $seat): bool => (int) $seat->table_id === $tableId && $this->resolver->isLive($seat));
            if ($live !== null) {
                if ((int) $live->merged_into_id === (int) $primary->id) {
                    $joined[] = $tableId;
                } else {
                    $refused[] = $tableId;
                }

                continue;
            }
            // QR-origin primaries have no client key. Their immutable UUID is
            // the deterministic primary key component for joined identities.
            $key = ($primary->client_request_id ?? $primary->uuid).'#'.$tableId;
            if ($seatings->firstWhere('client_request_id', $key) !== null) {
                // A cleared joined generation cannot be resurrected.
                $refused[] = $tableId;

                continue;
            }
            $seat = TableSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $primary->company_id,
                'branch_id' => $primary->branch_id,
                'table_id' => $tableId,
                'status' => TableSession::STATUS_OPEN,
                'origin' => $primary->origin,
                'opened_by_device_id' => $device->id,
                'order_id' => $order?->id,
                'merged_into_id' => $primary->id,
                'client_request_id' => $key,
                'temp_reference' => null,
                'opened_at' => $at,
                'expires_at' => $primary->expires_at,
            ]);
            $seatings->put((int) $seat->id, $seat);
            if ($order !== null) {
                DB::table('pos_order_tables')->insertOrIgnore(['order_id' => $order->id, 'table_id' => $tableId]);
            }
            $this->journal->handle($seat, 'joined', ['primary_table_session_uuid' => $primary->uuid], (int) $device->id);
            $joined[] = $tableId;
            $created++;
        }

        return ['joined' => $joined, 'refused' => $refused, 'created' => $created];
    }
}
