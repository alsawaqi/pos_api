<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark stale in-flight claims as lapsed without declaring that no charge occurred.
 *
 * The charge_outcome IS NULL predicate is safety-critical: uncertain and
 * approved claims, and already-lapsed claims, must never be changed by time.
 */
final class SweepStaleQrCharges extends Command
{
    protected $signature = 'qr:sweep-stale-charges
        {--grace-seconds= : Override the configured post-deadline safety margin}';

    protected $description = 'Stamp stale in-flight QR charge claims as lapsed';

    public function handle(): int
    {
        $configuredGrace = max(1, (int) config('qr.charge_sweep_grace_seconds', 30));
        $rawGrace = $this->option('grace-seconds') ?? $configuredGrace;
        $graceSeconds = filter_var($rawGrace, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($graceSeconds === false) {
            $this->error('--grace-seconds must be a positive integer.');

            return self::INVALID;
        }

        $now = now();
        $lapsed = DB::table('pos_orders')
            ->where('status', 'awaiting_payment')
            ->whereNotNull('charge_claimed_at')
            ->whereNull('charge_outcome')
            ->where('charge_deadline_at', '<=', $now->copy()->subSeconds($graceSeconds))
            ->update([
                'charge_outcome' => Order::CHARGE_OUTCOME_LAPSED,
                'updated_at' => $now,
            ]);

        $this->info("lapsed={$lapsed} grace_seconds={$graceSeconds}");

        return self::SUCCESS;
    }
}
