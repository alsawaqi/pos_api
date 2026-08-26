<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneQrSessions extends Command
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = ['closed', 'expired', 'cancelled'];

    protected $signature = 'qr:prune-sessions
        {--retention-days=30 : Delete terminal sessions closed at least this many days ago}';

    protected $description = 'Expire stale pending QR sessions and prune retained terminal sessions';

    public function handle(): int
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

        $deleted = DB::table('pos_qr_sessions')
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $now->copy()->subDays($retentionDays))
            ->delete();

        $this->info("expired={$expired} deleted={$deleted}");

        return self::SUCCESS;
    }
}
