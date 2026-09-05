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
        {--retention-days=30 : Delete terminal sessions closed at least this many days ago}';

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

        $deleted = DB::table('pos_qr_sessions')
            ->whereIn('status', self::TERMINAL_STATUSES)
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
