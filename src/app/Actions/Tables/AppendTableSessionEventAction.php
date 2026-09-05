<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Order;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Queue under the writer's transaction; flush after all domain writes, never
 * after the physical commit. Nested helpers must not take journal locks early.
 * The provider owns commit/savepoint bookkeeping. Sync actions may flush at
 * their final domain-write boundary to obtain ids before the transport ack.
 */
final class AppendTableSessionEventAction
{
    public const PGSQL_ADVISORY_LOCK_KEY = 814200206;

    /** @var WeakMap<Connection, list<array{event: TableSessionEvent, depth: int, flushed_depth: int|null}>> */
    private WeakMap $entries;

    public function __construct()
    {
        $this->entries = new WeakMap;
    }

    /** @param array<string, mixed> $payload IDs/outcomes only, never priced lines or credentials. */
    public function handle(
        TableSession $seating,
        string $eventType,
        array $payload = [],
        ?int $deviceId = null,
        ?CarbonInterface $at = null,
    ): TableSessionEvent {
        if (! in_array($eventType, TableSessionEvent::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Unknown table-session journal event.');
        }

        $connection = $seating->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new RuntimeException('Table-session journal writes require a transaction.');
        }

        $event = new TableSessionEvent;
        $event->setConnection($connection->getName());
        $event->fill([
            'company_id' => (int) $seating->company_id,
            'branch_id' => (int) $seating->branch_id,
            'table_session_id' => (int) $seating->getKey(),
            'table_id' => (int) $seating->table_id,
            'event_type' => $eventType,
            'device_id' => $deviceId,
            'payload' => array_replace([
                'table_session_uuid' => (string) $seating->uuid,
                'order_uuid' => $seating->order_id === null ? null : Order::on($connection->getName())
                    ->whereKey((int) $seating->order_id)
                    ->where('company_id', (int) $seating->company_id)
                    ->where('branch_id', (int) $seating->branch_id)
                    ->value('uuid'),
            ], $payload),
            'created_at' => $at ?? now(),
        ]);
        $entries = $this->entries[$connection] ?? [];
        $entries[] = ['event' => $event, 'depth' => $connection->transactionLevel(), 'flushed_depth' => null];
        $this->entries[$connection] = $entries;

        return $event;
    }

    /** @param array<string, mixed> $payload */
    public function forOrder(Order $order, string $eventType, array $payload = [], ?int $deviceId = null): ?TableSessionEvent
    {
        if ($order->table_session_id === null) {
            return null;
        }

        $seating = TableSession::on($order->getConnectionName())
            ->whereKey((int) $order->table_session_id)
            ->where('company_id', (int) $order->company_id)
            ->where('branch_id', (int) $order->branch_id)
            ->where('table_id', (int) $order->table_id)
            ->first();
        if ($seating === null) {
            throw new RuntimeException('The journal seating does not match its order tenant.');
        }

        return $this->handle($seating, $eventType, array_replace(['order_uuid' => (string) $order->uuid], $payload), $deviceId);
    }

    /** @return list<TableSessionEvent> The newly written models, in cursor order. */
    public function flush(?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $entries = $this->entries[$connection] ?? [];
        $written = [];
        $locked = false;
        foreach ($entries as $index => $entry) {
            if ($entry['flushed_depth'] !== null) {
                continue;
            }
            if ($connection->transactionLevel() === 0) {
                throw new RuntimeException('A journal cannot be flushed after commit.');
            }

            if (! $locked && $connection->getDriverName() === 'pgsql') {
                // A plain sequence allocates ids before commit. Serializing
                // journal inserts through commit prevents a visible higher id
                // from hiding a still-uncommitted lower id behind a feed cursor.
                $connection->selectOne('SELECT pg_advisory_xact_lock(?) AS locked', [self::PGSQL_ADVISORY_LOCK_KEY]);
                $locked = true;
            }

            $entry['event']->save();
            $entries[$index]['flushed_depth'] = $connection->transactionLevel();
            $written[] = $entry['event'];
            $this->entries[$connection] = $entries;
        }

        return $written;
    }

    public function committing(Connection $connection): void
    {
        try {
            $this->flush($connection);
        } catch (Throwable $exception) {
            // Laravel's commit-exception path does not roll back arbitrary
            // listener failures. Keep failed journal/domain writes atomic.
            $connection->rollBack(0);
            throw $exception;
        }
    }

    public function committed(Connection $connection, bool $logicalBoundary): void
    {
        $depth = $connection->transactionLevel();
        if ($depth === 0) {
            unset($this->entries[$connection]);

            return;
        }

        // RefreshDatabase's actual transaction manager identifies its outer
        // rollback-only wrapper. No APP_ENV switch or production early flush.
        if ($logicalBoundary) {
            $this->flush($connection);
        }
        $entries = $this->entries[$connection] ?? [];
        foreach ($entries as $index => $entry) {
            $entries[$index]['depth'] = min($entry['depth'], $depth);
            if ($entry['flushed_depth'] !== null) {
                $entries[$index]['flushed_depth'] = min($entry['flushed_depth'], $depth);
            }
        }
        $this->entries[$connection] = $entries;
    }

    public function rolledBack(Connection $connection): void
    {
        $depth = $connection->transactionLevel();
        $retained = [];
        foreach ($this->entries[$connection] ?? [] as $entry) {
            if ($entry['depth'] > $depth || ($entry['flushed_depth'] !== null && $entry['flushed_depth'] > $depth)) {
                $entry['event']->exists = false;
                unset($entry['event']->id);
                $entry['event']->syncOriginal();
                $entry['flushed_depth'] = null;
            }
            if ($entry['depth'] <= $depth) {
                $retained[] = $entry;
            }
        }
        $this->entries[$connection] = $retained;
    }
}
