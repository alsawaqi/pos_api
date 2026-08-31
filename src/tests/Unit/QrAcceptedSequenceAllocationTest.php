<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Qr\AllocateQrRoundAcceptedSequenceAction;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class QrAcceptedSequenceAllocationTest extends TestCase
{
    public function test_postgres_serializes_commit_visibility_before_allocating_nextval(): void
    {
        $connection = new class
        {
            public function getDriverName(): string
            {
                return 'pgsql';
            }
        };

        DB::shouldReceive('transactionLevel')
            ->once()
            ->ordered()
            ->andReturn(1);
        DB::shouldReceive('connection')
            ->once()
            ->ordered()
            ->andReturn($connection);
        DB::shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT pg_advisory_xact_lock(?) AS locked',
                [AllocateQrRoundAcceptedSequenceAction::PGSQL_ADVISORY_LOCK_KEY],
            )
            ->ordered()
            ->andReturn((object) ['locked' => null]);
        DB::shouldReceive('selectOne')
            ->once()
            ->with(
                "SELECT nextval('".
                AllocateQrRoundAcceptedSequenceAction::PGSQL_SEQUENCE.
                "') AS accepted_seq",
            )
            ->ordered()
            ->andReturn((object) ['accepted_seq' => '41']);

        $this->assertSame(
            41,
            (new AllocateQrRoundAcceptedSequenceAction)->next(),
        );
    }

    public function test_allocation_refuses_to_run_outside_a_transaction(): void
    {
        DB::shouldReceive('transactionLevel')
            ->once()
            ->andReturn(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an active transaction');

        (new AllocateQrRoundAcceptedSequenceAction)->next();
    }
}
