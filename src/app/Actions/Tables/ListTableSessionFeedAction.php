<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Device;
use App\Models\TableSessionEvent;

/** Append-only, branch-local cursor. Reading never expires a credential/seating. */
final class ListTableSessionFeedAction
{
    public function __construct(private readonly ListTableBoardAction $board) {}

    /** @return array{events: list<array<string, mixed>>, latest_id: int, has_more: bool} */
    public function handle(Device $device, ?int $after = null, int $limit = 50): array
    {
        $this->board->assertReader($device);
        $limit = min(100, max(1, $limit));
        $query = TableSessionEvent::query()
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id);
        $latestId = (int) (clone $query)->max('id');

        // Initial polling begins at the newest bounded window, but every
        // response remains ascending. Subsequent polls resume after an id.
        $events = $after === null
            ? (clone $query)->where('id', '<=', $latestId)->orderByDesc('id')->limit($limit)->get()->reverse()->values()
            : (clone $query)->where('id', '>', $after)->where('id', '<=', $latestId)->orderBy('id')->limit($limit)->get();
        $lastId = $events->last()?->id;

        return [
            'events' => $events->map(static function (TableSessionEvent $event): array {
                $payload = $event->payload ?? [];

                return [
                    'id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'table_id' => (int) $event->table_id,
                    'table_session_uuid' => $payload['table_session_uuid'] ?? null,
                    'order_uuid' => $payload['order_uuid'] ?? null,
                    'payload' => $payload,
                    'device_id' => $event->device_id === null ? null : (int) $event->device_id,
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            })->all(),
            'latest_id' => $latestId,
            'has_more' => $lastId !== null && (int) $lastId < $latestId,
        ];
    }
}
