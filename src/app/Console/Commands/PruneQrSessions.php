<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Qr\ExpireAbandonedTableSessionsAction;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneQrSessions extends Command
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = ['closed', 'expired', 'cancelled'];

    protected $signature = 'qr:prune-sessions
        {--retention-days=30 : Delete terminal sessions closed at least this many days ago}
        {--scan-retention-days=90 : Delete table scan rows older than this many days}';

    protected $description = 'Expire stale pending QR sessions and prune retained terminal sessions';

    public function handle(ExpireAbandonedTableSessionsAction $seatings): int
    {
        $retentionDays = filter_var($this->option('retention-days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($retentionDays === false) {
            $this->error('--retention-days must be a positive integer.');

            return self::INVALID;
        }
        $scanRetentionDays = filter_var($this->option('scan-retention-days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($scanRetentionDays === false) {
            $this->error('--scan-retention-days must be a positive integer.');

            return self::INVALID;
        }

        $now = now();
        $expired = DB::table('pos_qr_sessions')
            ->where('status', 'pending')
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => 'expired',
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

        $seatingsExpired = $seatings->handle($now);
        DB::table('pos_qr_session_scans')
            ->where('scanned_at', '<', $now->copy()->subDays($scanRetentionDays))
            ->delete();

        $deleted = DB::table('pos_qr_sessions')
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where(function (Builder $retained): void {
                // Handover does not rewrite historical round ownership. Keep
                // its released credential while any retained bill owns a round,
                // otherwise the credential FK would cascade-delete bill history.
                $retained->whereNull('released_at')->orWhere(function (Builder $released): void {
                    $released->whereNotExists(function (Builder $rounds): void {
                        $rounds->selectRaw('1')->from('pos_qr_order_rounds')
                            ->join('pos_orders', 'pos_orders.id', '=', 'pos_qr_order_rounds.order_id')
                            ->whereColumn('pos_qr_order_rounds.qr_session_id', 'pos_qr_sessions.id');
                    })->whereNotExists(function (Builder $children): void {
                        // Even a phone that added no round connects its ancestor
                        // rounds to the current owner; never cut that chain.
                        $children->selectRaw('1')->from('pos_qr_sessions as handovers')
                            ->whereColumn('handovers.handover_from_id', 'pos_qr_sessions.id');
                    });
                });
            })
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $now->copy()->subDays($retentionDays))
            ->whereNotExists(function (Builder $orders): void {
                $orders
                    ->selectRaw('1')
                    ->from('pos_orders')
                    ->whereColumn('pos_orders.qr_session_id', 'pos_qr_sessions.id');
            })
            ->delete();

        $this->info("expired={$expired} deleted={$deleted} seatings_expired={$seatingsExpired}");

        return self::SUCCESS;
    }
}
