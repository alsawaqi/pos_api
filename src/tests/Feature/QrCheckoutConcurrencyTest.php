<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Proves checkout idempotency through ten simultaneous HTTP-kernel requests.
 *
 * This test deliberately owns a file-backed SQLite database instead of using
 * RefreshDatabase's process-local :memory: connection. SQLite is configured
 * with WAL, a busy timeout and IMMEDIATE transactions so it models the
 * serialization provided by the production row locks without sharing the
 * PostgreSQL test/development service.
 */
final class QrCheckoutConcurrencyTest extends TestCase
{
    private const CHECKOUT_URL = '/api/v1/public/qr/checkout';

    private const WORKER_COUNT = 10;

    public function test_ten_concurrent_replays_return_one_identical_order_response(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            throw new RuntimeException('The QR checkout concurrency proof requires PCNTL and Unix socket pairs.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'pos-api-qr-checkout-');
        if ($databasePath === false) {
            throw new RuntimeException('Unable to allocate the disposable SQLite database.');
        }

        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->configureSqlite($this->app, $databasePath);
            DB::purge('sqlite');

            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--database' => 'sqlite',
                '--force' => true,
            ]));

            $this->seedCheckoutProduct();
            $station = Device::factory()->paired('mdev_qr_concurrency_station')->create([
                'company_id' => 100,
                'branch_id' => 10,
                'device_type' => 'payment_station',
            ]);
            $secret = 'ten-worker-concurrency-secret';
            $session = $this->createQrSession($station, $secret);
            $clientRequestId = 'ten-worker-same-request';
            $payload = [
                'client_request_id' => $clientRequestId,
                'checkout_choice' => 'machine',
                'phone' => '90001234',
                'plate_number' => '12345 A',
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 1,
                    'addon_ids' => [],
                    'notes' => null,
                ]],
            ];

            // No inherited PDO handle may cross the fork boundary.
            DB::disconnect('sqlite');

            $results = $this->runConcurrentRequests(
                $databasePath,
                (string) $session->uuid,
                $secret,
                $payload,
            );

            $this->assertCount(self::WORKER_COUNT, $results);
            $expectedStatus = $results[0]['status'];
            $expectedBody = $results[0]['body'];

            foreach ($results as $worker => $result) {
                $this->assertNull(
                    $result['error'],
                    sprintf('Worker %d failed: %s', $worker, (string) $result['error']),
                );
                $this->assertSame(201, $result['status'], sprintf('Worker %d returned a non-201 response.', $worker));
                $this->assertSame($expectedStatus, $result['status'], sprintf('Worker %d returned a different status.', $worker));
                $this->assertSame($expectedBody, $result['body'], sprintf('Worker %d returned a different body.', $worker));
            }

            DB::purge('sqlite');
            $order = Order::query()->sole();

            $this->assertDatabaseCount('pos_orders', 1);
            $this->assertDatabaseCount('pos_order_items', 1);
            $this->assertSame((int) $session->id, (int) $order->qr_session_id);
            $this->assertSame($clientRequestId, $order->client_request_id);
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
            $this->assertSame(
                (string) $order->uuid,
                (string) data_get(json_decode($expectedBody, true, 512, JSON_THROW_ON_ERROR), 'data.order.uuid'),
            );
        } finally {
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

    /**
     * LAUNCH-P2 P2-7 — sell, but warn: concurrent customers are never refused
     * on the shelf count (it was: only 3 of 10 could buy 3 units). Every buyer
     * gets an order and admission still writes no stock.
     */
    public function test_distinct_concurrent_customers_all_buy_even_past_the_shelf_count(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            throw new RuntimeException('The QR stock concurrency proof requires PCNTL and Unix socket pairs.');
        }
        $databasePath = tempnam(sys_get_temp_dir(), 'pos-api-qr-checkout-');
        if ($databasePath === false) {
            throw new RuntimeException('Unable to allocate the disposable SQLite database.');
        }
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');
        try {
            $this->configureSqlite($this->app, $databasePath);
            DB::purge('sqlite');
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]));
            $this->seedCheckoutProduct();
            DB::table('pos_products')->where('id', 1)->update(['stock_mode' => 'unit']);
            DB::table('pos_branch_product')->insert([
                'branch_id' => 10, 'product_id' => 1, 'is_available' => true,
                'stock_qty' => '3.000', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $secret = 'synthetic-concurrent-stock-secret';
            $sessionUuids = [];
            for ($worker = 0; $worker < self::WORKER_COUNT; $worker++) {
                // Different station locks: the balance, not one device/session,
                // must serialize competing buyers.
                $station = Device::factory()->paired('mdev_stock_worker_'.$worker)->create([
                    'company_id' => 100, 'branch_id' => 10, 'device_type' => 'payment_station',
                ]);
                $sessionUuids[] = (string) $this->createQrSession($station, $secret)->uuid;
            }
            $payload = [
                'client_request_id' => 'independent-stock-request', 'checkout_choice' => 'counter',
                'phone' => '90001234',
                'lines' => [['product_id' => 1, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ];
            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $sessionUuids[0], $secret, $payload, $sessionUuids);
            $this->assertCount(self::WORKER_COUNT, $results);
            $created = 0;
            $refused = 0;
            foreach ($results as $result) {
                $this->assertNull($result['error']);
                if ($result['status'] === 201) {
                    $created++;
                } else {
                    $this->assertSame(422, $result['status'], $result['body']);
                    $this->assertSame('product_unavailable', data_get(json_decode($result['body'], true), 'errors.0.code'));
                    $refused++;
                }
            }
            $this->assertSame(self::WORKER_COUNT, $created);
            $this->assertSame(0, $refused);
            DB::purge('sqlite');
            $this->assertDatabaseCount('pos_orders', self::WORKER_COUNT);
            $this->assertDatabaseCount('pos_order_items', self::WORKER_COUNT);
            $this->assertSame((float) self::WORKER_COUNT, (float) DB::table('pos_order_items')->sum('qty'));
            $this->assertSame(3.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
            $this->assertDatabaseCount('pos_product_stock_movements', 0);
        } finally {
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $sessionUuids
     * @return list<array{status: int, body: string, error: string|null}>
     */
    private function runConcurrentRequests(
        string $databasePath,
        string $sessionUuid,
        string $secret,
        array $payload,
        array $sessionUuids = [],
    ): array {
        $workers = [];

        for ($worker = 0; $worker < self::WORKER_COUNT; $worker++) {
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

                throw new RuntimeException('Unable to fork a checkout worker.');
            }

            if ($pid === 0) {
                foreach ($workers as $inheritedWorker) {
                    fclose($inheritedWorker['socket']);
                }
                fclose($parentSocket);

                $this->runChild(
                    $childSocket,
                    $databasePath,
                    $sessionUuids[$worker] ?? $sessionUuid,
                    $secret,
                    $payload,
                );
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

        // Every ready child is released only after all ten have booted and
        // reached this barrier, so their HTTP requests genuinely overlap.
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
                $results[] = ['status' => 0, 'body' => '', 'error' => $worker['protocol_error']];

                continue;
            }

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => sprintf('Worker %d exited abnormally (wait status %d).', $index, $status),
                ];

                continue;
            }

            if ($message === false || $message === '') {
                $results[] = ['status' => 0, 'body' => '', 'error' => "Worker {$index} returned no result."];

                continue;
            }

            $prefix = $message[0];
            try {
                /** @var array{status?: int, body?: string, error?: string|null} $decoded */
                $decoded = json_decode(substr($message, 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $results[] = [
                    'status' => 0,
                    'body' => '',
                    'error' => sprintf('Worker %d returned invalid IPC data: %s', $index, $exception->getMessage()),
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
            ];
        }

        return $results;
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function runChild(
        $socket,
        string $databasePath,
        string $sessionUuid,
        string $secret,
        array $payload,
    ): never {
        $ready = false;

        try {
            $app = $this->bootWorkerApplication($databasePath);
            $kernel = $app->make(HttpKernel::class);
            $request = Request::create(
                self::CHECKOUT_URL,
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_QR_SESSION' => $sessionUuid,
                    'HTTP_X_QR_CLIENT_SECRET' => $secret,
                    'REMOTE_ADDR' => '127.0.0.1',
                ],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            $this->writeAll($socket, 'R');
            $ready = true;
            if (fread($socket, 1) !== 'G') {
                throw new RuntimeException('The parent did not release the checkout start barrier.');
            }

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            $this->writeAll($socket, 'J'.json_encode([
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
                'error' => null,
            ], JSON_THROW_ON_ERROR));
            $app['db']->disconnect('sqlite');
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $result = json_encode([
                'status' => 0,
                'body' => '',
                'error' => get_class($exception).': '.$exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
            $this->writeAll($socket, ($ready ? 'J' : 'F').$result);
            fclose($socket);
            exit(0);
        }
    }

    private function bootWorkerApplication(string $databasePath): Application
    {
        // PHPUnit forces :memory: in the parent process; override the child
        // environment before boot so no provider can resolve that connection.
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $databasePath;
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$databasePath);
        putenv('DB_URL=');

        /** @var Application $app */
        $app = require base_path('bootstrap/app.php');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $kernel = $app->make(HttpKernel::class);
        $kernel->bootstrap();
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

    private function createQrSession(Device $station, string $secret): QrSession
    {
        $now = now();

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addMinutes(30),
        ]);
    }

    private function seedCheckoutProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Concurrent Checkout Latte',
            'base_price' => '2.500',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }
}
