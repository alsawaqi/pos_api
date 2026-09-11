<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use Closure;
use Illuminate\Support\Facades\DB;

/** A consistent display snapshot, never a reservation or a write transaction. */
final class TableReadSnapshot
{
    public function handle(Closure $read): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)
            || ($driver === 'pgsql' && $connection->transactionLevel() !== 0)) {
            throw new QrDineInException('table_snapshot_unavailable', 503, 'A consistent table snapshot is unavailable. Retry this read.');
        }

        return $connection->transaction(function () use ($connection, $driver, $read): mixed {
            if ($driver === 'pgsql') {
                // First statement, transaction-local: no session defaults,
                // row locks, expiry sweep, reservations or journal writes.
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $read();
        });
    }
}
