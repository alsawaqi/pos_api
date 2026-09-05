<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\TableSessionOrigin;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class CloseStaffTableSessionAction
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
        return $this->resolver->locked($device, $payload, 'close', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $row = $resolved['row'];
            $primary = $resolved['primary'];
            $at = Carbon::parse($payload['closed_at']);
            if ($row === null) {
                $row = TableSession::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                    'table_id' => (int) $payload['table_id'],
                    'client_request_id' => $payload['seating_key'],
                    'origin' => TableSessionOrigin::forDevice($device),
                    'status' => TableSession::STATUS_CLOSED,
                    'opened_by_device_id' => $device->id,
                    'closed_by_device_id' => $device->id,
                    'opened_at' => $at,
                    'expires_at' => $at,
                    'closed_at' => $at,
                    'close_reason' => TableSession::CLOSE_STAFF_CLOSE,
                ]);

                return $this->resolver->result('tombstoned', $payload, $row);
            }
            $order = $primary === null ? null : $orders->get((int) $primary->order_id);
            if (in_array($row->status, [TableSession::STATUS_CLOSED, TableSession::STATUS_EXPIRED], true)) {
                return $this->resolver->result('already_closed', $payload, $row, $primary, $order);
            }
            if (($row->status === TableSession::STATUS_MERGED && $row->close_reason !== TableSession::CLOSE_ATTACHED)
                || ! $this->resolver->isLive($resolved['winner']) || ! $this->resolver->isLive($primary)) {
                return $this->resolver->result('stale_generation', $payload, $row, $primary, $order);
            }
            if ($order !== null && ! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_PENDING_VERIFICATION], true)) {
                return $this->resolver->result('bill_unpaid', $payload, $row, $primary, $order);
            }
            $family = $seatings->filter(fn (TableSession $seat): bool => $this->resolver->isLive($seat)
                && ((int) $seat->id === (int) $primary->id || ((int) $seat->merged_into_id === (int) $primary->id && $seat->order_id === $primary->order_id)));
            foreach ($sessions as $session) {
                if ($family->has((int) $session->table_session_id)
                    && in_array($session->status, QrSession::EXPIRABLE_STATUSES, true)) {
                    // Closing a bill-less QR party must retire its credential
                    // too; an old phone cannot append into a closed generation.
                    $session->update(['status' => QrSession::STATUS_CLOSED, 'closed_at' => $at]);
                }
            }
            foreach ($family as $seat) {
                $seat->update([
                    'status' => TableSession::STATUS_CLOSED,
                    'closed_at' => $at,
                    'closed_by_device_id' => $device->id,
                    'close_reason' => TableSession::CLOSE_STAFF_CLOSE,
                ]);
                $this->journal->handle($seat, 'closed', ['close_reason' => TableSession::CLOSE_STAFF_CLOSE], (int) $device->id);
            }

            return $this->resolver->result('closed', $payload, $row, $primary, $order) + ['closed_count' => $family->count()];
        });
    }
}
