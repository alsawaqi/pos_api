<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SweepPaymentReversals extends Command
{
    protected $signature = 'pos:reversals-sweep';

    protected $description = 'Move unreported card reversals older than fifteen minutes to review';

    public function handle(): int
    {
        $count = 0;
        $cutoff = now()->subMinutes(15);
        DB::table('pos_payment_reversals')->where('status', 'pending')->where('attempted_at', '<', $cutoff)
            ->orderBy('id')->chunkById(100, function ($rows) use ($cutoff, &$count): void {
                foreach ($rows as $row) {
                    $count += DB::transaction(function () use ($row, $cutoff): int {
                        DB::table('pos_orders')->where('id', $row->order_id)->lockForUpdate()->first();
                        $payment = DB::table('pos_payments')->where('id', $row->payment_id)->lockForUpdate()->first();
                        $changed = DB::table('pos_payment_reversals')->where('id', $row->id)
                            ->where('status', 'pending')->where('attempted_at', '<', $cutoff)
                            ->update(['status' => 'uncertain', 'updated_at' => now()]);
                        if ($changed) {
                            $receipt = json_decode((string) $payment->bank_response, true);
                            $receipt = is_array($receipt) ? $receipt : [];
                            $receipt['reversal_pending_review'] = true;
                            DB::table('pos_payments')->where('id', $payment->id)->update([
                                'bank_response' => json_encode($receipt, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                            ]);
                        }

                        return $changed;
                    }, 5);
                }
            });
        $this->info('Moved '.$count.' stale reversal(s) to uncertain.');

        return self::SUCCESS;
    }
}
