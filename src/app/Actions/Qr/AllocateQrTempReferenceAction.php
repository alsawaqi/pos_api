<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Branch/day temporary identities, independent of devices and order numbering.
 *
 * The single clock read uses the application UTC day: midnight is 04:00 in
 * Asia/Muscat, so 01:30 on September 6 still uses T-0905. A merchant's official
 * prefix may also start T-; the two identities remain in separate columns.
 *
 * Checkout locks QR session -> temp sequence; payment locks order -> official
 * sequence. Never acquire both counters in one transaction. T2's planned
 * pos_table_session_sequences should reuse this table.
 */
final class AllocateQrTempReferenceAction
{
    public function handle(int $companyId, int $branchId): string
    {
        $today = now();
        $date = $today->toDateString();

        $number = DB::transaction(function () use ($companyId, $branchId, $today, $date): int {
            DB::table('pos_temp_reference_sequences')->insertOrIgnore([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'seq_date' => $date,
                'next_number' => 1,
                'created_at' => $today,
                'updated_at' => $today,
            ]);

            $sequence = DB::table('pos_temp_reference_sequences')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('seq_date', $date)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException('Temporary reference sequence was not found.');
            }

            $number = (int) $sequence->next_number;
            DB::table('pos_temp_reference_sequences')
                ->where('id', (int) $sequence->id)
                ->update([
                    'next_number' => $number + 1,
                    'updated_at' => $today,
                ]);

            return $number;
        });

        return 'T-'.$today->format('md').'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
