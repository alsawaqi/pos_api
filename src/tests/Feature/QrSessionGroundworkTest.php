<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrSessionGroundworkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qrSession(array $attributes = []): int
    {
        $now = now();
        $deviceId = (int) ($attributes['device_id'] ?? 1);
        DB::table('pos_devices')->insertOrIgnore([
            'id' => $deviceId,
            'uuid' => (string) Str::uuid(),
            'serial_number' => "qr-groundwork-device-{$deviceId}",
        ]);

        return (int) DB::table('pos_qr_sessions')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $deviceId,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'status' => 'pending',
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertOrder(int $sessionId, string $status, array $attributes = []): void
    {
        DB::table('pos_orders')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'qr_session_id' => $sessionId,
            'order_type' => 'quick',
            'status' => $status,
            'source' => 'main_pos',
            'opened_at' => now(),
        ], $attributes));
    }

    public function test_sqlite_mirror_has_the_qr_columns_and_named_indexes(): void
    {
        $this->assertCount(17, Schema::getColumnListing('pos_qr_sessions'));
        foreach ([
            'qr_session_id',
            'charge_device_id',
            'charge_amount_baisas',
            'charge_roundup_amount_baisas',
            'charge_claimed_at',
            'charge_deadline_at',
            'charge_outcome',
            'client_request_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('pos_orders', $column), $column);
        }

        $sessionIndexes = collect(DB::select("PRAGMA index_list('pos_qr_sessions')"))->keyBy('name');
        $orderIndexes = collect(DB::select("PRAGMA index_list('pos_orders')"))->keyBy('name');
        $paymentIndexes = collect(DB::select("PRAGMA index_list('pos_payments')"))->keyBy('name');

        $this->assertTrue($sessionIndexes->has('pos_qr_sessions_device_status_idx'));
        $this->assertTrue($sessionIndexes->has('pos_qr_sessions_status_expires_idx'));
        $this->assertTrue($orderIndexes->has('pos_orders_qr_session_live_unique'));
        $this->assertSame(1, (int) $orderIndexes['pos_orders_qr_session_live_unique']->unique);
        $this->assertSame(1, (int) $orderIndexes['pos_orders_qr_session_live_unique']->partial);
        $this->assertTrue($orderIndexes->has('pos_orders_qr_session_idx'));
        $this->assertTrue($orderIndexes->has('pos_orders_status_charge_deadline_idx'));
        $this->assertTrue($orderIndexes->has('pos_orders_qr_session_request_unique'));
        $this->assertSame(1, (int) $orderIndexes['pos_orders_qr_session_request_unique']->unique);
        $this->assertTrue($paymentIndexes->has('pos_payments_softpos_ref_idx'));
    }

    public function test_client_request_id_is_unique_only_within_its_qr_session(): void
    {
        $firstSession = $this->qrSession();
        $secondSession = $this->qrSession();

        $this->insertOrder($firstSession, Order::STATUS_PAID, ['client_request_id' => 'request-1']);
        $this->insertOrder($firstSession, Order::STATUS_PAID, ['client_request_id' => 'request-2']);
        $this->insertOrder($secondSession, Order::STATUS_PAID, ['client_request_id' => 'request-1']);
        $this->insertOrder($firstSession, Order::STATUS_PAID);
        $this->insertOrder($firstSession, Order::STATUS_PAID);

        try {
            $this->insertOrder($firstSession, Order::STATUS_PAID, ['client_request_id' => 'request-1']);
            $this->fail('The same client request id was accepted twice for one QR session.');
        } catch (QueryException) {
            // Expected: the composite unique rejects only this repeated pair.
        }

        $this->assertSame(4, DB::table('pos_orders')->where('qr_session_id', $firstSession)->count());
        $this->assertSame(1, DB::table('pos_orders')->where('qr_session_id', $secondSession)->count());
    }

    public function test_one_qr_session_cannot_have_two_nonterminal_orders(): void
    {
        $sessionId = $this->qrSession();
        $this->insertOrder($sessionId, Order::STATUS_OPEN);

        try {
            $this->insertOrder($sessionId, Order::STATUS_HELD);
            $this->fail('The live-session partial unique index accepted two nonterminal orders.');
        } catch (QueryException) {
            // Expected: both rows satisfy the complement-of-terminal predicate.
        }

        DB::table('pos_orders')
            ->where('qr_session_id', $sessionId)
            ->update(['status' => Order::STATUS_PAID]);

        $this->insertOrder($sessionId, Order::STATUS_HELD);

        $this->assertSame(2, DB::table('pos_orders')->where('qr_session_id', $sessionId)->count());
    }

    public function test_pruner_expires_pending_and_deletes_only_retained_terminal_rows(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);

        $expiredPending = $this->qrSession(['expires_at' => $now->copy()->subMinute()]);
        $futurePending = $this->qrSession(['expires_at' => $now->copy()->addMinute()]);
        $bound = $this->qrSession([
            'status' => 'bound',
            'expires_at' => $now->copy()->subDays(60),
            'closed_at' => $now->copy()->subDays(60),
        ]);
        $oldClosed = $this->qrSession([
            'status' => 'closed',
            'expires_at' => $now->copy()->subDays(31),
            'closed_at' => $now->copy()->subDays(31),
        ]);
        $oldExpired = $this->qrSession([
            'status' => 'expired',
            'expires_at' => $now->copy()->subDays(31),
            'closed_at' => $now->copy()->subDays(31),
        ]);
        $oldCancelled = $this->qrSession([
            'status' => 'cancelled',
            'expires_at' => $now->copy()->subDays(31),
            'closed_at' => $now->copy()->subDays(31),
        ]);
        $recentTerminal = $this->qrSession([
            'status' => 'expired',
            'expires_at' => $now->copy()->subDays(29),
            'closed_at' => $now->copy()->subDays(29),
        ]);
        $boundBefore = DB::table('pos_qr_sessions')->where('id', $bound)->first();

        $this->artisan('qr:prune-sessions')
            ->expectsOutput('expired=1 deleted=3')
            ->assertSuccessful();

        $this->assertDatabaseHas('pos_qr_sessions', [
            'id' => $expiredPending,
            'status' => 'expired',
            'closed_at' => $now,
        ]);
        $this->assertDatabaseHas('pos_qr_sessions', ['id' => $futurePending, 'status' => 'pending']);
        $this->assertEquals($boundBefore, DB::table('pos_qr_sessions')->where('id', $bound)->first());
        $this->assertDatabaseMissing('pos_qr_sessions', ['id' => $oldClosed]);
        $this->assertDatabaseMissing('pos_qr_sessions', ['id' => $oldExpired]);
        $this->assertDatabaseMissing('pos_qr_sessions', ['id' => $oldCancelled]);
        $this->assertDatabaseHas('pos_qr_sessions', ['id' => $recentTerminal]);
    }

    public function test_pruner_rejects_a_nonpositive_retention_window(): void
    {
        $this->artisan('qr:prune-sessions', ['--retention-days' => 0])
            ->expectsOutput('--retention-days must be a positive integer.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_pruner_keeps_an_old_closed_session_referenced_by_an_order(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);

        $sessionId = $this->qrSession([
            'status' => 'closed',
            'expires_at' => $now->copy()->subDays(31),
            'closed_at' => $now->copy()->subDays(31),
        ]);
        $this->insertOrder($sessionId, Order::STATUS_PAID);

        $this->artisan('qr:prune-sessions')
            ->expectsOutput('expired=0 deleted=0')
            ->assertSuccessful();

        $this->assertDatabaseHas('pos_qr_sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('pos_orders', ['qr_session_id' => $sessionId]);
    }

    public function test_pruner_is_scheduled_hourly_with_overlap_guards(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'qr:prune-sessions'));

        $this->assertNotNull($scheduled);
        $this->assertSame('0 * * * *', $scheduled->expression);
        $this->assertTrue($scheduled->withoutOverlapping);
        $this->assertSame(60, $scheduled->expiresAt);
        $this->assertTrue($scheduled->onOneServer);
    }
}
