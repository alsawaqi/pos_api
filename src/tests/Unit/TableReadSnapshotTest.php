<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\TableReadSnapshot;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TableReadSnapshotTest extends TestCase
{
    public function test_postgres_snapshot_is_read_only_repeatable_and_set_before_any_read(): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $steps = [];
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function (Closure $read) use (&$steps): mixed {
            $steps[] = 'transaction';

            return $read();
        });
        $connection->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')
            ->andReturnUsing(function () use (&$steps): bool {
                $steps[] = 'snapshot';

                return true;
            });
        $result = (new TableReadSnapshot)->handle(function () use (&$steps): array {
            $steps[] = 'read';

            return ['one' => 'bill'];
        });
        $this->assertSame(['one' => 'bill'], $result);
        $this->assertSame(['transaction', 'snapshot', 'read'], $steps);
    }

    public function test_sqlite_uses_transaction_without_postgres_statement(): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $connection->shouldNotReceive('statement');
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $read) => $read());
        $this->assertSame(17, (new TableReadSnapshot)->handle(fn () => 17));
    }

    public static function unsafeConnections(): array
    {
        return ['nested postgres' => ['pgsql', 1], 'unsupported driver' => ['mysql', 0]];
    }

    #[DataProvider('unsafeConnections')]
    public function test_incompatible_snapshot_fails_before_read(string $driver, int $level): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        if ($driver === 'pgsql') {
            $connection->shouldReceive('transactionLevel')->once()->andReturn($level);
        }
        $connection->shouldNotReceive('transaction');
        $connection->shouldNotReceive('statement');
        try {
            (new TableReadSnapshot)->handle(fn () => $this->fail('No read is allowed without the snapshot contract.'));
            $this->fail('An unsafe snapshot must be refused.');
        } catch (QrDineInException $error) {
            $this->assertSame('table_snapshot_unavailable', $error->codeName);
            $this->assertSame(503, $error->httpStatus);
        }
    }
}
