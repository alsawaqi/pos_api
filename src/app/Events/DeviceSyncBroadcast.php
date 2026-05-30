<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * §11.5 — real-time push for a processed device sync event.
 *
 * Emitted once the dispatcher has durably stamped a sync event `processed`, so
 * the OTHER terminals at the branch (a second register, a handheld, the kitchen
 * display) learn about an order/shift/expense/restock the moment it settles.
 *
 * One generic event keyed by `type` (the sync event_type, e.g. `order.create`,
 * `order.pay`) rather than a class per event — the client listens by name and
 * routes on `type`. The payload is intentionally LEAN: the originating device
 * already holds the full data; everyone else gets the server refs (result_json)
 * and pulls detail (GET /device/orders/active) only if they need it.
 *
 * ShouldBroadcastNow (not queued): the device API container runs no queue
 * worker, and a POS push is worthless if delayed — so it ships inline. The
 * dispatcher wraps the send in its own try/catch, so a Reverb outage degrades
 * to "no live push" without ever failing the already-durable domain event.
 */
final class DeviceSyncBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $companyId,
        public readonly ?int $branchId,
        public readonly int $eventId,
        public readonly string $type,
        /** @var array<string, mixed>|null */
        public readonly ?array $result,
    ) {}

    public static function fromProcessed(SyncEvent $event, Device $device): self
    {
        return new self(
            companyId: (int) $device->company_id,
            branchId: $device->branch_id !== null ? (int) $device->branch_id : null,
            eventId: (int) $event->getKey(),
            type: (string) $event->event_type,
            result: $event->result_json,
        );
    }

    /**
     * The branch operational stream — the main POS / handheld / KDS feed.
     * Falls back to the company channel for a branchless device (rare).
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->branchId !== null) {
            return [new PrivateChannel("branch.{$this->branchId}")];
        }

        return [new PrivateChannel("company.{$this->companyId}")];
    }

    /**
     * Listen by the sync event_type so a client subscribes per concern.
     */
    public function broadcastAs(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'event_id' => $this->eventId,
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'result' => $this->result,
        ];
    }
}
