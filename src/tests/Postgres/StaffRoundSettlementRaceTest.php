<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Qr\EnsureTableSessionForQrSessionAction;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\QrDineInConcurrencyTest;
use Tests\TestCase;
use Throwable;

/** Explicit-only suite: refuses SQLite and any database outside the disposable harness. */
final class StaffRoundSettlementRaceTest extends TestCase
{
    public function test_staff_round_and_settlement_are_serialized_on_real_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('qrfix2_test', DB::connection()->getDatabaseName());
        $this->assertSame('qrfix2-pg', config('database.connections.pgsql.host'));
        $this->assertTrue(function_exists('pcntl_fork'));
        // The W11 runner has already applied the authoritative admin schema.
        // Keep the old standalone SQLite-mirror harness available explicitly.
        if (getenv('LAUNCH_P0_DISPOSABLE') !== '1') {
            $connection = DB::connection();
            $connection->useDefaultSchemaGrammar();
            $originalGrammar = $connection->getSchemaGrammar();
            $grammar = new DeferredForeignKeysGrammar($connection);
            $connection->setSchemaGrammar($grammar);
            try {
                $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
                foreach ($grammar->foreignKeys as $sql) {
                    DB::statement($sql);
                }
            } finally {
                $connection->setSchemaGrammar($originalGrammar);
            }

        } else {
            $this->assertTrue(DB::table('pos_admin_migrations')->where('migration', '2026_09_30_000001_bind_and_hash_pos_device_credentials')->exists());
            DB::table('pos_companies')->insert(['id' => 100, 'uuid' => Str::uuid(), 'name' => 'F08 synthetic', 'status' => 'active']);
        }

        Branch::create(['id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'F08 disposable', 'status' => 'active', 'latitude' => null, 'longitude' => null]);
        $fixture = new QrDineInConcurrencyTest('test_ten_simultaneous_open_table_requests_create_exactly_one_live_session');
        $make = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod($fixture, $method))->invoke($fixture, ...$args);
        $make('seedRoundProduct');
        $station = $make('paymentStation', 'mdev_f08_station');
        $staff = $make('attendedTill', 'mdev_f08_staff');
        $claimant = $make('attendedTill', 'mdev_f08_claim');
        for ($run = 0; $run < 3; $run++) {
            $table = $make('activeTable', 'F08 race '.$run);
            [$session, $order] = $make('runningDineInOrder', $station, $table);
            // The PostgreSQL fixture is balanced independently of the legacy
            // SQLite fixture whose separate repair follows F08.
            if (! $order->items()->exists()) {
                $item = OrderItem::create(['order_id' => $order->id, 'product_id' => 99001,
                    'product_name_snapshot' => 'Concurrent coffee', 'qty' => '1.000',
                    'unit_price_snapshot' => '4.750', 'line_discount' => '0.000', 'line_total' => '4.750']);
                QrOrderRound::where('order_id', $order->id)->update(['priced_lines' => json_encode([[
                    'product_id' => 99001, 'order_item_id' => $item->id, 'product_name' => 'Concurrent coffee',
                    'qty' => 1, 'addons' => [], 'line_total_baisas' => 4750,
                ]], JSON_THROW_ON_ERROR)]);
            }
            $seat = DB::transaction(fn () => app(EnsureTableSessionForQrSessionAction::class)->handle($session, $order, $station));
            $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
                'client_timestamp' => now()->toIso8601String(), 'payload' => [
                    'table_id' => $table->id, 'seating_key' => (string) Str::uuid(), 'queued_offline' => false,
                    'order_uuid' => $order->uuid, 'client_request_id' => (string) Str::uuid(),
                    'submitted_at' => now()->toIso8601String(),
                    'lines' => [['product_id' => 99001, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
                ]];
            $requests = [
                ['/api/v1/device/sync/push', $staff->plainTextToken, ['events' => [$event]]],
                ['/api/v1/device/qr/claim-settlement', $claimant->plainTextToken, ['order_uuid' => $order->uuid]],
            ];
            $dir = sys_get_temp_dir().'/f08-'.Str::uuid();
            mkdir($dir, 0700);
            $pids = [];
            // Never inherit an open PDO connection across a fork.
            DB::disconnect();
            foreach ($requests as $worker => $request) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not fork PostgreSQL worker');
                }
                if ($pid === 0) {
                    $this->worker($worker, $request, $dir);
                }
                $pids[] = $pid;
            }
            try {
                foreach ($pids as $pid) {
                    pcntl_waitpid($pid, $status);
                    $this->assertTrue(pcntl_wifexited($status));
                    $this->assertSame(0, pcntl_wexitstatus($status));
                }
                $results = [];
                foreach ([0, 1] as $worker) {
                    $results[] = json_decode(file_get_contents($dir.'/'.$worker.'.json'), true, 512, JSON_THROW_ON_ERROR);
                }
                fwrite(STDOUT, "\nF08_POSTGRES_RUN=".json_encode(['run' => $run, 'driver' => 'pgsql', 'results' => $results], JSON_THROW_ON_ERROR)."\n");
                $this->assertSame(200, $results[0]['status'], json_encode($results));
                $this->assertSame('processed', data_get($results[0], 'body.data.results.0.status'), json_encode($results));
                $this->assertSame(200, $results[1]['status'], json_encode($results));
                $this->assertStringNotContainsString('SQLSTATE', json_encode($results));
                DB::purge();
                $fresh = Order::findOrFail($order->id);
                $this->assertSame('awaiting_payment', $fresh->status);
                $this->assertSame(0, $fresh->payments()->count());
                $this->assertSame(1, DB::table('pos_table_sessions')->where('table_id', $table->id)->whereIn('status', ['open', 'billing', 'closing'])->count());
                $this->assertSame($seat->id, (int) $fresh->table_session_id);
                $this->assertSame(1, DB::table('pos_sync_events')->where('device_id', $staff->id)->where('client_event_id', $event['client_event_id'])->count());
                $this->assertSame((int) round((float) $fresh->grand_total * 1000), (int) $fresh->charge_amount_baisas);
            } finally {
                foreach (glob($dir.'/*') as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }

        // A loser at a nested savepoint needs the dispatcher to retry the
        // outer transaction; retrying only ResolveStaffSeating cannot do so.
        $mode = 'retry';
        $injected = 0;
        DB::listen(function (QueryExecuted $query) use (&$mode, &$injected): void {
            if ($mode === 'none' || ! str_contains($query->sql, 'from "pos_orders"')
                || ! str_contains($query->sql, 'for update')) {
                return;
            }
            if ($mode === 'retry') {
                if ($injected++ === 0) {
                    throw new DeadlockException('deadlock detected: F08 injected nested loser');
                }

                return;
            }
            throw new QueryException('pgsql', 'select private_data from internal_table', [], new PDOException('SQLSTATE[23505] internal-host'));
        });
        $event['client_event_id'] = (string) Str::uuid();
        $response = $this->withToken($staff->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]]);
        $mode = 'none';
        $response->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertGreaterThanOrEqual(2, $injected);

        $mode = 'error';
        $event['client_event_id'] = (string) Str::uuid();
        $response = $this->withToken($staff->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]]);
        $mode = 'none';
        $response->assertOk()->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', 'Could not save this update. Retry the same request.');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('internal-host', $response->getContent());

        $mode = 'error';
        $response = $this->withToken($claimant->plainTextToken)->postJson('/api/v1/device/qr/claim-settlement', ['order_uuid' => $order->uuid]);
        $mode = 'none';
        $response->assertStatus(503)->assertJsonPath('errors.0.code', 'settlement_retry_required');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('internal-host', $response->getContent());
        fwrite(STDOUT, "\nF08_NESTED_RETRY_AND_SAFE_ERRORS=PASS\n");

        $history = Order::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $staff->id, 'order_type' => 'dine_in', 'source' => 'main_pos', 'table_id' => $table->id,
            'status' => 'paid', 'subtotal' => 0, 'discount_total' => 0, 'comp_total' => 0,
            'tax_total' => 0, 'grand_total' => 0, 'opened_at' => now(), 'closed_at' => now()]);
        $barrierDir = sys_get_temp_dir().'/f08-history-'.Str::uuid();
        mkdir($barrierDir, 0700);
        DB::disconnect();
        $holder = pcntl_fork();
        if ($holder === 0) {
            DB::purge();
            DB::transaction(function () use ($history, $barrierDir): void {
                Order::whereKey($history->id)->lockForUpdate()->firstOrFail();
                touch($barrierDir.'/held');
                $until = microtime(true) + 10;
                while (! is_file($barrierDir.'/release') && microtime(true) < $until) {
                    usleep(1000);
                }
            });
            DB::disconnect();
            exit(0);
        }
        $this->assertGreaterThan(0, $holder);
        try {
            $until = microtime(true) + 5;
            while (! is_file($barrierDir.'/held') && microtime(true) < $until) {
                usleep(1000);
            }
            $this->assertFileExists($barrierDir.'/held');
            DB::purge();
            DB::statement("SET lock_timeout = '300ms'");
            $event['client_event_id'] = (string) Str::uuid();
            $this->withToken($staff->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
            fwrite(STDOUT, "\nF08_UNRELATED_PAID_ORDER_LOCK=IGNORED\n");
        } finally {
            touch($barrierDir.'/release');
            pcntl_waitpid($holder, $holderStatus);
            foreach (glob($barrierDir.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrierDir);
        }
    }

    private function worker(int $worker, array $request, string $dir): never
    {
        try {
            DB::purge();
            DB::statement("SET lock_timeout = '15s'");
            // Force the staff transaction to detect the old cycle first. The
            // application, not the test, must recover its whole transaction.
            DB::statement($worker === 0 ? "SET deadlock_timeout = '50ms'" : "SET deadlock_timeout = '5s'");
            $barrier = false;
            DB::listen(function (QueryExecuted $query) use ($worker, $dir, &$barrier): void {
                if ($barrier || ! str_contains($query->sql, 'for ')) {
                    return;
                }
                $target = $worker === 0 ? 'from "pos_tables"' : 'from "pos_orders"';
                if (! str_contains($query->sql, $target)) {
                    return;
                }
                $barrier = true;
                touch($dir.'/locked-'.$worker);
                $until = microtime(true) + 10;
                while (! is_file($dir.'/locked-'.(1 - $worker))) {
                    if (microtime(true) > $until) {
                        throw new RuntimeException('Lock barrier timed out');
                    }
                    usleep(1000);
                }
            });
            $http = Request::create($request[0], 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$request[1], 'REMOTE_ADDR' => '127.0.0.1',
            ], json_encode($request[2], JSON_THROW_ON_ERROR));
            $kernel = app(Kernel::class);
            $response = $kernel->handle($http);
            $kernel->terminate($http, $response);
            $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
        } catch (Throwable $e) {
            $result = ['status' => 0, 'exception' => $e::class.': '.$e->getMessage()];
        }
        file_put_contents($dir.'/'.$worker.'.json', json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }
}
