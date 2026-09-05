<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\EnsureTableSessionForQrSessionAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tests\TestCase;
use Throwable;

/**
 * Proves the two QR dine-in admission locks through simultaneous HTTP requests.
 *
 * Like QrCheckoutConcurrencyTest, this owns a disposable file-backed SQLite
 * database. WAL, a busy timeout and IMMEDIATE transactions model production's
 * serialization without reaching any shared PostgreSQL database.
 */
final class QrDineInConcurrencyTest extends TestCase
{
    private const CLAIM_URL = '/api/v1/device/qr/claim-charge';

    private const CLAIM_SETTLEMENT_URL = '/api/v1/device/qr/claim-settlement';

    private const CONFIRM_ROUND_URL = '/api/v1/device/qr/confirm-round';

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const OPEN_WORKER_COUNT = 10;

    public function test_ten_simultaneous_open_table_requests_create_exactly_one_live_session(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-open-table-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);

            $firstStation = $this->paymentStation('mdev_qr_open_concurrency_one');
            $secondStation = $this->paymentStation('mdev_qr_open_concurrency_two');
            $table = $this->activeTable('Concurrency T-1');
            $requests = [];

            for ($worker = 0; $worker < self::OPEN_WORKER_COUNT; $worker++) {
                $requests[] = [
                    'url' => self::OPEN_TABLE_URL,
                    'token' => $worker % 2 === 0
                        ? (string) $firstStation->device_token
                        : (string) $secondStation->device_token,
                    'payload' => ['table_id' => (int) $table->getKey()],
                    'observe_table_id' => (int) $table->getKey(),
                ];
            }

            // No inherited PDO handle may cross the fork boundary.
            DB::disconnect('sqlite');

            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertCount(self::OPEN_WORKER_COUNT, $results);

            $created = 0;
            $refused = 0;
            foreach ($results as $worker => $result) {
                $this->assertNull(
                    $result['error'],
                    sprintf('Open-table worker %d failed: %s', $worker, (string) $result['error']),
                );
                $this->assertNotSame(0, $result['status'], "Open-table worker {$worker} returned no HTTP status.");
                $this->assertNotSame(500, $result['status'], "Open-table worker {$worker} hit a lock/server error.");
                $this->assertLessThanOrEqual(
                    1,
                    $result['live_table_sessions'],
                    "Open-table worker {$worker} observed duplicate live sessions.",
                );

                $body = $this->decodeBody($result['body']);
                if ($result['status'] === 201) {
                    $created++;
                    $this->assertNotEmpty(data_get($body, 'data.session_uuid'));
                    $this->assertSame((string) $table->qr_token, data_get($body, 'data.table_token'));

                    continue;
                }

                $refused++;
                $this->assertSame(409, $result['status'], "Open-table worker {$worker} was not a classified loser.");
                $this->assertSame('qr_table_already_open', data_get($body, 'errors.0.code'));
            }

            $this->assertSame(1, $created);
            $this->assertSame(self::OPEN_WORKER_COUNT - 1, $refused);

            DB::purge('sqlite');
            $liveStatuses = [
                QrSession::STATUS_PENDING,
                QrSession::STATUS_ACTIVE,
                QrSession::STATUS_ORDERED,
            ];
            $this->assertSame(
                1,
                QrSession::query()->where('table_id', $table->getKey())->count(),
            );
            $this->assertSame(
                1,
                QrSession::query()
                    ->where('table_id', $table->getKey())
                    ->whereIn('status', $liveStatuses)
                    ->count(),
            );
            $this->assertSame(
                QrSession::STATUS_PENDING,
                QrSession::query()->where('table_id', $table->getKey())->sole()->status,
            );
            $seating = DB::table('pos_table_sessions')->where('table_id', $table->id)->sole();
            $this->assertSame('open', $seating->status);
            $this->assertSame(
                (int) $seating->id,
                (int) QrSession::query()->where('table_id', $table->id)->sole()->table_session_id,
            );
            $this->assertSame(1, DB::table('pos_table_sessions')->where('table_id', $table->id)
                ->whereIn('status', ['open', 'billing', 'closing'])->count());
            $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    public function test_backfill_shaped_ensure_and_first_round_race_create_one_seating_and_one_reference(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-seating-attach-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);
            $station = $this->paymentStation('mdev_qr_seating_concurrency');
            $table = $this->activeTable('Concurrency seating');
            $this->seedRoundProduct();
            $session = QrSession::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'device_id' => $station->id, 'table_id' => $table->id,
                'token' => hash('sha256', (string) Str::uuid()),
                'token_expires_at' => now()->addHours(6), 'expires_at' => now()->addHours(6),
                'client_secret_hash' => QrSession::hashClientSecret('seating-race-secret'),
                'status' => QrSession::STATUS_ACTIVE, 'bound_at' => now(), 'last_seen_at' => now(),
            ]);
            $requests = [[
                'url' => '/api/v1/public/qr/table-round',
                'token' => '',
                'payload' => [],
                'observe_table_id' => (int) $table->id,
                'ensure_session_id' => (int) $session->id,
            ], [
                'url' => '/api/v1/public/qr/table-round',
                'token' => '',
                'headers' => ['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'seating-race-secret'],
                'payload' => [
                    'client_request_id' => 'seating-race-first-round',
                    'phone' => '92000091',
                    'lines' => [['product_id' => 99001, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
                ],
                'observe_table_id' => (int) $table->id,
            ]];
            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertCount(2, $results);
            foreach ($results as $result) {
                $this->assertNull($result['error'], (string) $result['error']);
                $this->assertNotSame(500, $result['status'], $result['body']);
            }
            $this->assertSame(200, $results[0]['status'], $results[0]['body']);
            $this->assertSame(201, $results[1]['status'], $results[1]['body']);
            DB::purge('sqlite');
            $seating = DB::table('pos_table_sessions')->where('table_id', $table->id)->sole();
            $order = Order::query()->where('qr_session_id', $session->id)->sole();
            $round = QrOrderRound::query()->where('qr_session_id', $session->id)->sole();
            $this->assertSame((int) $seating->id, (int) $session->fresh()->table_session_id);
            $this->assertSame((int) $seating->id, (int) $order->table_session_id);
            $this->assertSame((int) $seating->id, (int) $round->table_session_id);
            $this->assertSame((int) $order->id, (int) $seating->order_id);
            $this->assertNotEmpty($seating->temp_reference);
            $this->assertSame($seating->temp_reference, $order->temp_reference);
            $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
            $this->assertDatabaseCount('pos_order_sequences', 0);
            $this->assertDatabaseCount('pos_table_session_events', 3);
            $this->assertSame(['opened', 'round_appended', 'customer_order_arrived'], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles($databasePath, $originalDefault, $originalSqlite);
        }
    }

    public function test_two_stations_concurrently_claim_one_order_with_one_exact_frozen_winner(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-claim-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);

            $firstStation = $this->paymentStation('mdev_qr_claim_concurrency_one');
            $secondStation = $this->paymentStation('mdev_qr_claim_concurrency_two');
            $table = $this->activeTable('Concurrency T-2');
            [$session, $order] = $this->orderedDineInOrder($firstStation, $table);
            $stationIds = [
                (int) $firstStation->getKey(),
                (int) $secondStation->getKey(),
            ];
            $requests = [
                [
                    'url' => self::CLAIM_URL,
                    'token' => (string) $firstStation->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
                [
                    'url' => self::CLAIM_URL,
                    'token' => (string) $secondStation->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
            ];

            DB::disconnect('sqlite');

            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertCount(2, $results);

            $winner = null;
            $loserCount = 0;
            foreach ($results as $worker => $result) {
                $this->assertNull(
                    $result['error'],
                    sprintf('Claim worker %d failed: %s', $worker, (string) $result['error']),
                );
                $this->assertNotSame(0, $result['status'], "Claim worker {$worker} returned no HTTP status.");
                $this->assertNotSame(500, $result['status'], "Claim worker {$worker} hit a lock/server error.");

                $body = $this->decodeBody($result['body']);
                if ($result['status'] === 200) {
                    $this->assertNull($winner, 'More than one station received a fresh claim.');
                    $winner = $worker;
                    $this->assertSame(4_750, data_get($body, 'data.charge_amount_baisas'));
                    $this->assertSame(4_750, data_get($body, 'data.softpos_amount_baisas'));
                    $this->assertFalse((bool) data_get($body, 'data.already_claimed_by_this_device'));

                    continue;
                }

                $loserCount++;
                $this->assertSame(409, $result['status']);
                $this->assertSame('charge_already_claimed', data_get($body, 'errors.0.code'));
            }

            $this->assertNotNull($winner);
            $this->assertSame(1, $loserCount);

            DB::purge('sqlite');
            $order->refresh();
            $session->refresh();
            $this->assertSame($stationIds[$winner], (int) $order->charge_device_id);
            $this->assertSame(4_750, (int) $order->charge_amount_baisas);
            $this->assertNull($order->charge_roundup_amount_baisas);
            $this->assertNotNull($order->charge_claimed_at);
            $this->assertNotNull($order->charge_deadline_at);
            $this->assertNull($order->charge_outcome);
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->status);
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    public function test_two_tills_concurrently_claim_one_running_tab_with_one_winner(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-settlement-claim-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);
            $station = $this->paymentStation('mdev_qr_settlement_opening_station');
            $firstTill = $this->attendedTill('mdev_qr_settlement_till_one');
            $secondTill = $this->attendedTill('mdev_qr_settlement_till_two');
            $table = $this->activeTable('Concurrency T-3');
            [$session, $order] = $this->runningDineInOrder($station, $table);
            $tillIds = [(int) $firstTill->id, (int) $secondTill->id];
            $requests = [
                [
                    'url' => self::CLAIM_SETTLEMENT_URL,
                    'token' => (string) $firstTill->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
                [
                    'url' => self::CLAIM_SETTLEMENT_URL,
                    'token' => (string) $secondTill->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
            ];

            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $requests);

            $winner = null;
            foreach ($results as $worker => $result) {
                $this->assertNull($result['error'], (string) $result['error']);
                $this->assertNotSame(500, $result['status']);
                $body = $this->decodeBody($result['body']);
                if ($result['status'] === 200) {
                    $this->assertNull($winner, 'Both tills acquired the settlement claim.');
                    $winner = $worker;
                    $this->assertSame(4750, data_get($body, 'data.charge_amount_baisas'));
                    $this->assertFalse((bool) data_get(
                        $body,
                        'data.already_claimed_by_this_device',
                    ));
                } else {
                    $this->assertSame(409, $result['status']);
                    $this->assertSame('charge_already_claimed', data_get($body, 'errors.0.code'));
                }
            }

            $this->assertNotNull($winner);
            DB::purge('sqlite');
            $order->refresh();
            $session->refresh();
            $this->assertSame($tillIds[$winner], (int) $order->charge_device_id);
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
            $this->assertSame(4750, (int) $order->charge_amount_baisas);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->status);
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    public function test_round_and_attended_claim_race_freezes_a_complete_tab_without_deadlock(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-settlement-round-race-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);
            $station = $this->paymentStation('mdev_qr_round_race_station');
            $till = $this->attendedTill('mdev_qr_round_race_till');
            $table = $this->activeTable('Concurrency T-4');
            [$session, $order] = $this->runningDineInOrder($station, $table);
            $this->seedRoundProduct();
            $requests = [
                [
                    'url' => self::CLAIM_SETTLEMENT_URL,
                    'token' => (string) $till->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
                [
                    'url' => '/api/v1/public/qr/table-round',
                    'token' => '',
                    'headers' => [
                        'X-QR-Session' => (string) $session->uuid,
                        'X-QR-Client-Secret' => 'concurrent-running-secret',
                    ],
                    'payload' => [
                        'client_request_id' => 'concurrent-second-round',
                        'lines' => [[
                            'product_id' => 99001,
                            'qty' => 1,
                            'addon_ids' => [],
                            'notes' => null,
                        ]],
                    ],
                    'observe_table_id' => null,
                ],
            ];

            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertSame(200, $results[0]['status'], (string) $results[0]['error']);
            $this->assertContains($results[1]['status'], [201, 409]);
            if ($results[1]['status'] === 409) {
                $this->assertSame(
                    'qr_round_session_not_active',
                    data_get($this->decodeBody($results[1]['body']), 'errors.0.code'),
                );
            } else {
                $this->assertSame(201, $results[1]['status']);
            }

            DB::purge('sqlite');
            $order->refresh();
            $session->refresh();
            $acceptedTotal = (int) QrOrderRound::query()
                ->where('order_id', $order->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->sum('total_baisas');
            $this->assertContains($acceptedTotal, [4750, 9500]);
            $this->assertSame($acceptedTotal, (int) $order->charge_amount_baisas);
            $this->assertSame($acceptedTotal, (int) round((float) $order->grand_total * 1000));
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->status);
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    public function test_two_independent_confirms_allocate_unique_sqlite_acceptance_sequences(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-accepted-seq-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);
            $firstStation = $this->paymentStation('mdev_qr_seq_station_one');
            $secondStation = $this->paymentStation('mdev_qr_seq_station_two');
            $firstTill = $this->attendedTill('mdev_qr_seq_till_one');
            $secondTill = $this->attendedTill('mdev_qr_seq_till_two');
            [$firstSession, $firstOrder] = $this->runningDineInOrder(
                $firstStation,
                $this->activeTable('Concurrency SEQ-1'),
            );
            [$secondSession, $secondOrder] = $this->runningDineInOrder(
                $secondStation,
                $this->activeTable('Concurrency SEQ-2'),
            );
            $firstRound = $this->pendingConfirmationRound($firstSession, $firstOrder);
            $secondRound = $this->pendingConfirmationRound($secondSession, $secondOrder);
            $requests = [
                [
                    'url' => self::CONFIRM_ROUND_URL,
                    'token' => (string) $firstTill->device_token,
                    'payload' => ['round_id' => (int) $firstRound->id],
                    'observe_table_id' => null,
                ],
                [
                    'url' => self::CONFIRM_ROUND_URL,
                    'token' => (string) $secondTill->device_token,
                    'payload' => ['round_id' => (int) $secondRound->id],
                    'observe_table_id' => null,
                ],
            ];

            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertCount(2, $results);
            foreach ($results as $worker => $result) {
                $this->assertNull(
                    $result['error'],
                    sprintf('Confirm worker %d failed: %s', $worker, (string) $result['error']),
                );
                $this->assertSame(
                    200,
                    $result['status'],
                    sprintf('Confirm worker %d did not resolve terminally.', $worker),
                );
                $this->assertSame(
                    QrOrderRound::STATUS_ACCEPTED,
                    data_get($this->decodeBody($result['body']), 'data.round.status'),
                );
            }

            DB::purge('sqlite');
            $rounds = QrOrderRound::query()
                ->whereIn('id', [$firstRound->id, $secondRound->id])
                ->orderBy('accepted_seq')
                ->get();
            $this->assertCount(2, $rounds);
            $this->assertSame(
                [1, 2],
                $rounds->pluck('accepted_seq')->map(static fn ($value): int => (int) $value)->all(),
            );
            $this->assertSame(
                [QrOrderRound::STATUS_ACCEPTED, QrOrderRound::STATUS_ACCEPTED],
                $rounds->pluck('status')->all(),
            );
            $this->assertSame(2, $rounds->pluck('accepted_seq')->unique()->count());
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    public function test_finish_and_attended_claim_race_has_no_lock_inversion_or_unreserved_state(): void
    {
        $this->assertConcurrencySupport();
        $databasePath = $this->allocateDatabase('pos-api-qr-settlement-finish-race-');
        $originalDefault = config('database.default');
        $originalSqlite = config('database.connections.sqlite');

        try {
            $this->migrateDisposableDatabase($databasePath);
            $station = $this->paymentStation('mdev_qr_finish_race_station');
            $till = $this->attendedTill('mdev_qr_finish_race_till');
            $table = $this->activeTable('Concurrency T-5');
            [$session, $order] = $this->runningDineInOrder($station, $table);
            $requests = [
                [
                    'url' => self::CLAIM_SETTLEMENT_URL,
                    'token' => (string) $till->device_token,
                    'payload' => ['order_uuid' => (string) $order->uuid],
                    'observe_table_id' => null,
                ],
                [
                    'url' => '/api/v1/public/qr/table-finish',
                    'token' => '',
                    'headers' => [
                        'X-QR-Session' => (string) $session->uuid,
                        'X-QR-Client-Secret' => 'concurrent-running-secret',
                    ],
                    'payload' => ['payment_choice' => 'counter'],
                    'observe_table_id' => null,
                ],
            ];

            DB::disconnect('sqlite');
            $results = $this->runConcurrentRequests($databasePath, $requests);
            $this->assertSame(200, $results[0]['status'], (string) $results[0]['error']);
            $this->assertContains($results[1]['status'], [200, 409]);
            if ($results[1]['status'] === 409) {
                $this->assertSame(
                    'qr_finish_session_not_active',
                    data_get($this->decodeBody($results[1]['body']), 'errors.0.code'),
                );
            } else {
                $this->assertSame(200, $results[1]['status']);
            }

            DB::purge('sqlite');
            $order->refresh();
            $session->refresh();
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
            $this->assertSame((int) $till->id, (int) $order->charge_device_id);
            $this->assertSame(4750, (int) $order->charge_amount_baisas);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->status);
        } finally {
            $this->restoreDatabaseConfigAndDeleteFiles(
                $databasePath,
                $originalDefault,
                $originalSqlite,
            );
        }
    }

    /**
     * @param  list<array{
     *     url: string,
     *     token: string,
     *     payload: array<string, mixed>,
     *     observe_table_id: int|null
     * }>  $requests
     * @return list<array{
     *     status: int,
     *     body: string,
     *     error: string|null,
     *     live_table_sessions: int
     * }>
     */
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

            if (isset($requestData['ensure_session_id'])) {
                // Model the admin backfill's table -> order -> session -> seating
                // transaction without adding any production endpoint or database.
                $seatingId = DB::transaction(function () use ($requestData): int {
                    PosTable::query()->lockForUpdate()->findOrFail($requestData['observe_table_id']);
                    $order = Order::query()->where('qr_session_id', $requestData['ensure_session_id'])
                        ->orderByDesc('id')->lockForUpdate()->first();
                    $session = QrSession::query()->lockForUpdate()->findOrFail($requestData['ensure_session_id']);

                    return (int) app(EnsureTableSessionForQrSessionAction::class)->handle($session, $order, null)->id;
                });
                $response = new JsonResponse(['table_session_id' => $seatingId]);
            } else {
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
            }

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

    private function paymentStation(string $token): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);
    }

    private function attendedTill(string $token): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'fixed_pos',
        ]);
    }

    private function activeTable(string $label): PosTable
    {
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => 'Concurrency Floor',
            'display_order' => 1,
            'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => $floor->getKey(),
            'label' => $label,
            'seats' => 4,
            'shape' => 'square',
            'qr_token' => hash('sha256', (string) Str::uuid()),
            'status' => 'active',
            'display_order' => 1,
        ]);
    }

    /** @return array{QrSession, Order} */
    private function orderedDineInOrder(Device $station, PosTable $table): array
    {
        $now = now();
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'table_id' => $table->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addHours(6),
            'client_secret_hash' => QrSession::hashClientSecret('concurrent-dine-in-secret'),
            'status' => QrSession::STATUS_ORDERED,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addHours(6),
        ]);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'qr_session_id' => $session->getKey(),
            'client_request_id' => (string) Str::uuid(),
            'table_id' => $table->getKey(),
            'order_type' => 'dine_in',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => $now,
            'receipt_number' => 'QR-CONCURRENT',
        ]);

        return [$session, $order];
    }

    /** @return array{QrSession, Order} */
    private function runningDineInOrder(Device $station, PosTable $table): array
    {
        $now = now();
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'table_id' => $table->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addHours(6),
            'client_secret_hash' => QrSession::hashClientSecret('concurrent-running-secret'),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addHours(6),
        ]);
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'qr_session_id' => $session->getKey(),
            'client_request_id' => (string) Str::uuid(),
            'table_id' => $table->getKey(),
            'order_type' => 'dine_in',
            'status' => Order::STATUS_OPEN,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => $now,
            'receipt_number' => 'QR-RUNNING',
        ]);
        QrOrderRound::query()->create([
            'qr_session_id' => $session->id,
            'order_id' => $order->id,
            'round_no' => 1,
            'status' => QrOrderRound::STATUS_ACCEPTED,
            'client_request_id' => 'concurrent-first-round',
            'priced_lines' => [['line_total_baisas' => 4750]],
            'subtotal_baisas' => 4750,
            'tax_baisas' => 0,
            'total_baisas' => 4750,
            'submitted_at' => $now,
            'resolved_at' => $now,
        ]);

        return [$session, $order];
    }

    private function pendingConfirmationRound(
        QrSession $session,
        Order $order,
    ): QrOrderRound {
        return QrOrderRound::query()->create([
            'qr_session_id' => $session->id,
            'order_id' => $order->id,
            'round_no' => 2,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [[
                'product_id' => null,
                'name' => 'Concurrent frozen coffee',
                'name_ar' => null,
                'qty' => 1,
                'notes' => null,
                'unit_price_baisas' => 4750,
                'line_discount_baisas' => 0,
                'line_total_baisas' => 4750,
                'addons' => [],
            ]],
            'confirm_payload' => [
                'version' => 1,
                'items' => [[
                    'attributes' => [
                        'product_id' => null,
                        'product_name_snapshot' => 'Concurrent frozen coffee',
                        'qty' => '1.000',
                        'unit_price_snapshot' => '4.750',
                        'line_discount' => '0.000',
                        'line_total' => '4.750',
                        'recipe_snapshot_json' => null,
                        'component_snapshot_json' => null,
                        'status' => 'open',
                        'notes' => null,
                    ],
                    'addons' => [],
                ]],
                'discounts' => [],
            ],
            'accepted_seq' => null,
            'subtotal_baisas' => 4750,
            'tax_baisas' => 0,
            'total_baisas' => 4750,
            'submitted_at' => now(),
            'resolved_at' => null,
            'resolved_by_device_id' => null,
        ]);
    }

    private function seedRoundProduct(): void
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            'id' => 99001,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Concurrent coffee',
            'base_price' => '4.750',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'deleted_at' => null,
        ] + $timestamps);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10,
            'product_id' => 99001,
            'is_available' => true,
            'stock_qty' => null,
        ] + $timestamps);
    }

    /** @return array<string, mixed> */
    private function decodeBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
