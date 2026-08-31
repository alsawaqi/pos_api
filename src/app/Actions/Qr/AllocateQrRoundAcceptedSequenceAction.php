<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\QrOrderRound;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Allocates the durable global acceptance-order cursor inside the caller's transaction. */
final class AllocateQrRoundAcceptedSequenceAction
{
    public const PGSQL_SEQUENCE = 'pos_qr_order_rounds_accepted_seq_seq';

    public const PGSQL_ADVISORY_LOCK_KEY = 814200205;

    public function next(): int
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('QR accepted-round sequencing requires an active transaction.');
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // nextval values are monotonic but concurrent transactions can
            // commit out of allocation order. This transaction-scoped lock
            // serializes allocation through commit, so a landed cursor can
            // never overtake an uncommitted lower sequence.
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(?) AS locked',
                [self::PGSQL_ADVISORY_LOCK_KEY],
            );
            $row = DB::selectOne(
                "SELECT nextval('".self::PGSQL_SEQUENCE."') AS accepted_seq",
            );
            $value = $row?->accepted_seq ?? null;
            if (! is_numeric($value) || (int) $value < 1) {
                throw new RuntimeException('The QR accepted-round sequence returned an invalid value.');
            }

            return (int) $value;
        }

        if ($driver === 'sqlite') {
            // SQLite has no independent sequence object. Callers hold the
            // canonical order -> session -> round transaction and retry it
            // five times; the unique index is the final collision fence.
            return ((int) QrOrderRound::query()->max('accepted_seq')) + 1;
        }

        throw new RuntimeException(
            'QR accepted-round sequencing is unsupported for database driver '.$driver.'.',
        );
    }
}
