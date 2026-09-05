<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Real commits on a disposable in-memory connection, not a rollback-only wrapper. */
final class TableSessionJournalTest extends TestCase
{
    private string $previousConnection;

    private TableSession $seating;

    private AppendTableSessionEventAction $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.table_journal_test' => array_replace(
            config('database.connections.sqlite'),
            ['database' => ':memory:', 'url' => null],
        )]);
        DB::setDefaultConnection('table_journal_test');
        (require database_path('migrations/0000_00_00_000000_create_test_schema.php'))->up();
        Branch::query()->create([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Journal branch', 'status' => 'active',
        ]);
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Journal floor', 'display_order' => 1, 'status' => 'active',
        ]);
        $table = Table::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => $floor->id,
            'label' => 'JOURNAL', 'seats' => 4, 'shape' => 'square', 'status' => 'active',
            'display_order' => 1,
        ]);
        $this->seating = TableSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'table_id' => $table->id, 'status' => 'open', 'origin' => 'station',
            'opened_at' => now(), 'expires_at' => now()->addHours(6),
        ]);
        $this->journal = app(AppendTableSessionEventAction::class);
    }

    protected function tearDown(): void
    {
        DB::purge('table_journal_test');
        DB::setDefaultConnection($this->previousConnection);
        parent::tearDown();
    }

    public function test_vocabulary_and_casts_match_the_append_only_schema(): void
    {
        $this->assertSame([
            'opened', 'attached', 'round_appended', 'round_pending', 'round_resolved',
            'billing', 'reopened', 'closed', 'expired', 'merged', 'moved', 'joined',
            'needs_review', 'customer_order_arrived', 'sent_to_counter', 'print_claimed', 'print_result',
        ], TableSessionEvent::EVENT_TYPES);
        $event = DB::transaction(fn () => $this->journal->handle($this->seating, 'opened'));
        $stored = TableSessionEvent::query()->sole();
        $this->assertSame((int) $event->id, (int) $stored->id);
        $this->assertFalse($stored->timestamps);
        $this->assertSame(['table_session_uuid' => $this->seating->uuid, 'order_uuid' => null], $stored->payload);
        $this->assertNotNull($stored->created_at);
        $this->assertArrayNotHasKey('updated_at', $stored->getAttributes());
        $this->assertSame(814200206, AppendTableSessionEventAction::PGSQL_ADVISORY_LOCK_KEY);
    }

    public function test_nested_helpers_do_not_flush_before_later_outer_domain_writes(): void
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if ($query->connectionName === 'table_journal_test'
                && preg_match('/^(insert|update|delete) /i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        DB::transaction(function (): void {
            $this->journal->handle($this->seating, 'opened');
            DB::transaction(function (): void {
                $this->seating->update(['temp_reference' => 'T-0905-001']);
                $this->journal->handle($this->seating, 'billing');
            });
            $this->assertDatabaseCount('pos_table_session_events', 0);
            $this->seating->update(['status' => 'billing', 'billing_at' => now()]);
            $this->assertDatabaseCount('pos_table_session_events', 0);
        });
        $this->assertSame(['opened', 'billing'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        $this->assertCount(4, $writes);
        $this->assertStringContainsString('update "pos_table_sessions"', $writes[1]);
        $this->assertStringContainsString('insert into "pos_table_session_events"', $writes[2]);
        $this->assertStringContainsString('insert into "pos_table_session_events"', $writes[3]);
    }

    public function test_explicit_final_flush_returns_real_ids_once_before_the_ack(): void
    {
        DB::transaction(function (): void {
            $first = $this->journal->handle($this->seating, 'opened');
            $second = $this->journal->handle($this->seating, 'joined');
            $this->assertNull($first->id);
            $this->assertSame([$first, $second], $this->journal->flush());
            $this->assertGreaterThan(0, $first->id);
            $this->assertGreaterThan($first->id, $second->id);
            $this->assertSame([], $this->journal->flush());
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertDatabaseCount('pos_table_session_events', 2);
    }

    public function test_nested_rollback_discards_only_its_pending_events(): void
    {
        $discarded = null;
        DB::transaction(function () use (&$discarded): void {
            $this->journal->handle($this->seating, 'opened');
            try {
                DB::transaction(function () use (&$discarded): void {
                    $discarded = $this->journal->handle($this->seating, 'closed');
                    throw new RuntimeException('rollback nested generation');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('rollback nested generation', $exception->getMessage());
            }
            $this->journal->handle($this->seating, 'billing');
        });
        $this->assertFalse($discarded->exists);
        $this->assertNull($discarded->id);
        $this->assertSame(['opened', 'billing'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_outer_event_flushed_in_rolled_back_savepoint_is_requeued_and_persisted_once(): void
    {
        $event = null;
        DB::transaction(function () use (&$event): void {
            $event = $this->journal->handle($this->seating, 'opened');
            try {
                DB::transaction(function (): void {
                    $this->journal->flush();
                    $this->assertDatabaseCount('pos_table_session_events', 1);
                    throw new RuntimeException('undo only the savepoint insert');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('undo only the savepoint insert', $exception->getMessage());
            }
            $this->assertFalse($event->exists);
            $this->assertNull($event->id);
            $this->assertDatabaseCount('pos_table_session_events', 0);
        });
        $this->assertTrue($event->exists);
        $this->assertSame((int) $event->id, (int) TableSessionEvent::query()->sole()->id);
    }

    public function test_outer_rollback_invalidates_ids_from_a_successful_nested_flush(): void
    {
        $event = null;
        try {
            DB::transaction(function () use (&$event): void {
                DB::transaction(function () use (&$event): void {
                    $event = $this->journal->handle($this->seating, 'opened');
                    $this->journal->flush();
                    $this->assertGreaterThan(0, $event->id);
                });
                throw new RuntimeException('undo full transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('undo full transaction', $exception->getMessage());
        }
        $this->assertFalse($event->exists);
        $this->assertNull($event->id);
        $this->assertDatabaseCount('pos_table_session_events', 0);
        DB::transaction(fn () => $this->journal->handle($this->seating, 'billing'));
        $this->assertSame('billing', TableSessionEvent::query()->sole()->event_type);
    }

    public function test_failed_commit_journal_insert_rolls_back_domain_writes_and_allows_clean_retry(): void
    {
        $event = null;
        $first = null;
        try {
            DB::transaction(function () use (&$event, &$first): void {
                $this->seating->update(['temp_reference' => 'must-rollback']);
                $first = $this->journal->handle($this->seating, 'billing');
                $event = $this->journal->handle($this->seating, 'opened');
                $event->table_id = 99999999;
            });
            $this->fail('A foreign-key-invalid journal row must fail the entire transaction.');
        } catch (QueryException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertNull($this->seating->fresh()->temp_reference);
        $this->assertFalse($first->exists);
        $this->assertNull($first->id);
        $this->assertFalse($event->exists);
        $this->assertNull($event->id);
        $this->assertDatabaseCount('pos_table_session_events', 0);
        DB::transaction(fn () => $this->journal->handle($this->seating, 'opened'));
        $this->assertDatabaseCount('pos_table_session_events', 1);
    }

    public function test_manual_begin_commit_uses_the_same_pre_commit_journal_boundary(): void
    {
        DB::beginTransaction();
        $event = $this->journal->handle($this->seating, 'opened');
        $this->assertNull($event->id);
        DB::commit();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame((int) $event->id, (int) TableSessionEvent::query()->sole()->id);
    }

    public function test_journal_cannot_be_queued_outside_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Table-session journal writes require a transaction.');
        $this->journal->handle($this->seating, 'opened');
    }
}
