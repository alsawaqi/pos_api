<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\Branch;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSessionEvent;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;
use Throwable;

/** Real HTTP workers, graph locks, journal and file-backed SQLite; no action doubles. */
final class TableCancellationConcurrencyTest extends TestCase
{
    use TableSessionFixtures;

    public static function competitors(): array
    {
        return [['append'], ['claim']];
    }

    #[DataProvider('competitors')]
    public function test_whole_cancel_and_competitor_are_atomic(string $competitor): void
    {
        $this->assertConcurrencySupport();
        $path = $this->allocateDatabase('t11-race-');
        $default = config('database.default');
        $sqlite = config('database.connections.sqlite');
        try {
            $this->migrateDisposableDatabase($path);
            $this->seedPosStaff([7]);
            $device = $this->seatingDevice();
            $seat = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
            $product = $this->seatingProduct();
            $product->update(['stock_mode' => 'ingredient']);
            $ingredient = DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => 'T11 race ingredient', 'unit' => 'kg', 'default_unit_cost' => 2]);
            DB::table('pos_product_recipes')->insert(['product_id' => $product->id, 'ingredient_id' => $ingredient, 'quantity' => .100, 'unit_at_set' => 'kg']);
            $prefix = ['seating_key' => $seat->client_request_id, 'table_id' => (int) $seat->table_id, 'queued_offline' => false, 'staff_id' => 7];
            $round = $prefix + ['client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $product->id, 'qty' => 3, 'addon_ids' => []]]];
            $this->withToken($device->device_token)->postJson('/api/v1/device/tables/'.$seat->uuid.'/round', $round)
                ->assertOk()->assertJsonPath('data.outcome', 'appended');
            $order = Order::query()->sole();
            $cancel = $prefix + ['client_request_id' => (string) Str::uuid(), 'reason' => 'T11 race', 'authorized_by' => 'T11 manager',
                'cancelled_at' => now()->toIso8601String(), 'lines' => [['client_request_id' => (string) Str::uuid(),
                    'product_id' => $product->id, 'addon_ids' => [], 'qty' => 3, 'prepared' => true]]];
            $requests = [['url' => '/api/v1/device/tables/'.$seat->uuid.'/cancel-bill', 'payload' => $cancel,
                'token' => $device->device_token, 'observe_table_id' => null]];
            $round['client_request_id'] = (string) Str::uuid();
            $round['lines'][0]['qty'] = 1;
            $requests[] = ['url' => $competitor === 'append' ? '/api/v1/device/tables/'.$seat->uuid.'/round' : '/api/v1/device/qr/claim-settlement',
                'payload' => $competitor === 'append' ? $round : ['order_uuid' => $order->uuid],
                'token' => $device->device_token, 'observe_table_id' => null];
            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($path, $requests);
            foreach ($results as $result) {
                $this->assertNull($result['error'], json_encode($results));
                $this->assertContains($result['status'], [200, 409], json_encode($results));
            }
            $bodies = array_map(static fn ($r) => json_decode($r['body'], true), $results);
            $won = ($bodies[0]['data']['outcome'] ?? null) === 'cancelled';
            $otherWon = $competitor === 'append' ? ($bodies[1]['data']['outcome'] ?? null) === 'appended' : $results[1]['status'] === 200;
            $this->assertNotSame($won, $otherWon, json_encode($results));
            $this->assertSame($won ? 'void' : ($competitor === 'append' ? 'open' : 'awaiting_payment'), $order->fresh()->status);
            $this->assertSame($won ? 'closed' : ($competitor === 'append' ? 'open' : 'billing'), $seat->fresh()->status);
            $this->assertDatabaseCount('pos_waste_records', $won ? 1 : 0);
            $this->assertDatabaseCount('pos_stock_movements', $won ? 1 : 0);
            $this->assertSame($won ? 1 : 0, TableSessionEvent::query()->where('event_type', 'bill_cancelled')->count());
            $this->assertDatabaseCount('pos_payments', 0);
            $this->assertNull($order->fresh()->receipt_number);
            if ($won) {
                $this->assertSame(-.300, (float) DB::table('pos_branch_stock')->value('quantity'));
                $this->assertSame(0.0, (float) $order->fresh()->grand_total);
            } else {
                $this->assertSame($competitor === 'append' ? 4.0 : 3.0, (float) $order->fresh()->items()->sum('qty'));
                $this->assertSame($competitor === 'append' ? 'bill_changed' : 'bill_reserved', $bodies[0]['errors'][0]['code']);
            }
            fwrite(STDOUT, "\nT11_RACE_".$competitor.'='.json_encode(['cancel_won' => $won, 'statuses' => array_column($results, 'status')])."\n");
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles($path, $default, $sqlite);
        }
    }

    private function runConcurrentRequests(string $databasePath, array $requests): array
    {
        $workers = [];

        foreach ($requests as $request) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($sockets === false) {
                $this->releaseAndReapWorkers($workers);

                throw new RuntimeException('Unable to create a worker IPC socket pair.');
            }

            [$parentSocket, $childSocket] = $sockets;
            $pid = pcntl_fork();

            if ($pid === -1) {
                fclose($parentSocket);
                fclose($childSocket);
                $this->releaseAndReapWorkers($workers);

                throw new RuntimeException('Unable to fork a QR dine-in worker.');
            }

            if ($pid === 0) {
                foreach ($workers as $inheritedWorker) {
                    fclose($inheritedWorker['socket']);
                }
                fclose($parentSocket);
                $this->runChild($childSocket, $databasePath, $request);
            }

            fclose($childSocket);
            stream_set_timeout($parentSocket, 30);
            $workers[] = ['pid' => $pid, 'socket' => $parentSocket, 'ready' => false];
        }

        foreach ($workers as $index => &$worker) {
            $signal = fread($worker['socket'], 1);
            $worker['ready'] = $signal === 'R';
            if (! $worker['ready'] && $signal !== 'F') {
                $worker['protocol_error'] = sprintf(
                    'Worker %d closed before reaching the start barrier.',
                    $index,
                );
            }
        }
        unset($worker);

        foreach ($workers as $worker) {
            if ($worker['ready']) {
                $this->writeAll($worker['socket'], 'G');
            }
        }

        $results = [];
        foreach ($workers as $index => $worker) {
            $status = 0;
            pcntl_waitpid($worker['pid'], $status);
            $message = stream_get_contents($worker['socket']);
            fclose($worker['socket']);

            if (isset($worker['protocol_error'])) {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => $worker['protocol_error'],
                    'live_table_sessions' => 0,
                ];

                continue;
            }

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => sprintf('Worker %d exited abnormally (wait status %d).', $index, $status),
                    'live_table_sessions' => 0,
                ];

                continue;
            }

            if ($message === false || $message === '') {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => "Worker {$index} returned no result.",
                    'live_table_sessions' => 0,
                ];

                continue;
            }

            $prefix = $message[0];
            try {
                /** @var array{status?: int, body?: string, error?: string|null, live_table_sessions?: int} $decoded */
                $decoded = json_decode(substr($message, 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => sprintf('Worker %d returned invalid IPC data: %s', $index, $exception->getMessage()),
                    'live_table_sessions' => 0,
                ];

                continue;
            }

            if ($prefix !== 'J') {
                $decoded['error'] = $decoded['error'] ?? "Worker {$index} failed before the barrier.";
            }

            $results[] = [
                'status' => (int) ($decoded['status'] ?? 0),
                'body' => (string) ($decoded['body'] ?? ''),
                'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
                'live_table_sessions' => (int) ($decoded['live_table_sessions'] ?? 0),
            ];
        }

        return $results;
    }

    /**
     * @param  resource  $socket
     * @param  array{
     *     url: string,
     *     token: string,
     *     payload: array<string, mixed>,
     *     observe_table_id: int|null
     * }  $requestData
     */
    private function runChild($socket, string $databasePath, array $requestData): never
    {
        $ready = false;

        try {
            $app = $this->bootWorkerApplication($databasePath);
            $kernel = $app->make(HttpKernel::class);
            $server = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '127.0.0.1',
            ];
            if ($requestData['token'] !== '') {
                $server['HTTP_AUTHORIZATION'] = 'Bearer '.$requestData['token'];
            }
            foreach (($requestData['headers'] ?? []) as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $request = Request::create(
                $requestData['url'],
                'POST',
                [],
                [],
                [],
                $server,
                json_encode($requestData['payload'], JSON_THROW_ON_ERROR),
            );

            $this->writeAll($socket, 'R');
            $ready = true;
            if (fread($socket, 1) !== 'G') {
                throw new RuntimeException('The parent did not release the QR dine-in start barrier.');
            }

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            $liveTableSessions = 0;
            if ($requestData['observe_table_id'] !== null) {
                $liveTableSessions = $app['db']->connection('sqlite')
                    ->table('pos_qr_sessions')
                    ->where('table_id', $requestData['observe_table_id'])
                    ->whereIn('status', [
                        QrSession::STATUS_PENDING,
                        QrSession::STATUS_ACTIVE,
                        QrSession::STATUS_ORDERED,
                    ])
                    ->count();
            }

            $this->writeAll($socket, 'J'.json_encode([
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
                'error' => null,
                'live_table_sessions' => $liveTableSessions,
            ], JSON_THROW_ON_ERROR));
            $app['db']->disconnect('sqlite');
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $result = json_encode([
                'status' => 0,
                'body' => '',
                'error' => get_class($exception).': '.$exception->getMessage(),
                'live_table_sessions' => 0,
            ], JSON_THROW_ON_ERROR);
            $this->writeAll($socket, ($ready ? 'J' : 'F').$result);
            fclose($socket);
            exit(0);
        }
    }

    private function bootWorkerApplication(string $databasePath): Application
    {
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $databasePath;
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        $_ENV['LOG_CHANNEL'] = $_SERVER['LOG_CHANNEL'] = 'null';
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$databasePath);
        putenv('DB_URL=');
        putenv('LOG_CHANNEL=null');

        /** @var Application $app */
        $app = require base_path('bootstrap/app.php');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $kernel = $app->make(HttpKernel::class);
        $kernel->bootstrap();
        $app['config']->set('logging.default', 'null');
        $app['log']->setDefaultDriver('null');
        $this->configureSqlite($app, $databasePath);
        $app['db']->purge('sqlite');

        return $app;
    }

    private function configureSqlite(Application $app, string $databasePath): void
    {
        $config = $app['config'];
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.url', null);
        $config->set('database.connections.sqlite.database', $databasePath);
        $config->set('database.connections.sqlite.foreign_key_constraints', true);
        $config->set('database.connections.sqlite.busy_timeout', 30_000);
        $config->set('database.connections.sqlite.journal_mode', 'WAL');
        $config->set('database.connections.sqlite.synchronous', 'NORMAL');
        $config->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
    }

    /**
     * @param  list<array{pid: int, socket: resource, ready: bool}>  $workers
     */
    private function releaseAndReapWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $this->writeAll($worker['socket'], 'G');
        }
        foreach ($workers as $worker) {
            pcntl_waitpid($worker['pid'], $status);
            fclose($worker['socket']);
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $message): void
    {
        $offset = 0;
        $length = strlen($message);

        while ($offset < $length) {
            $written = fwrite($socket, substr($message, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write to the worker IPC socket.');
            }
            $offset += $written;
        }
    }

    private function assertConcurrencySupport(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            throw new RuntimeException('The QR dine-in concurrency proof requires PCNTL and Unix socket pairs.');
        }
    }

    private function allocateDatabase(string $prefix): string
    {
        $databasePath = tempnam(sys_get_temp_dir(), $prefix);
        if ($databasePath === false) {
            throw new RuntimeException('Unable to allocate the disposable SQLite database.');
        }

        return $databasePath;
    }

    private function migrateDisposableDatabase(string $databasePath): void
    {
        $this->configureSqlite($this->app, $databasePath);
        DB::purge('sqlite');

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]));
        Branch::query()->create([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Concurrency branch', 'status' => 'active',
            'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500,
        ]);
    }

    /**
     * @param  array<string, mixed>  $originalSqlite
     */
    private function restoreDatabaseConfigAndDeleteFiles(
        string $databasePath,
        string $originalDefault,
        array $originalSqlite,
    ): void {
        DB::purge('sqlite');
        config()->set('database.default', $originalDefault);
        config()->set('database.connections.sqlite', $originalSqlite);
        DB::purge('sqlite');

        foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
