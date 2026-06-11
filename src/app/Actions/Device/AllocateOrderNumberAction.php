<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Support\OrderNumbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-F8 — atomically allocate the next order number for a device's company.
 *
 * The SERVER owns the counter so every device at a branch/company draws
 * from one sequence (the device's local counter is only its OFFLINE
 * fallback). Scope per the merchant's `order_numbering` setting:
 *   - scope 'branch'  → the device's branch row (each branch independent);
 *   - scope 'company' → the company-wide row (branch_id NULL).
 * daily_reset on → today's row (server tz, like the rest of the date
 * logic); a new day simply materialises a fresh row starting at 1.
 *
 * ATOMICITY (works on live Postgres AND the sqlite test path):
 *   1. insertOrIgnore the scope row — compiles to INSERT … ON CONFLICT DO
 *      NOTHING (pg) / INSERT OR IGNORE (sqlite). The functional unique
 *      index pos_order_sequences_scope_unique (company, COALESCE(branch,0),
 *      COALESCE(seq_date,'1970-01-01')) dedupes a concurrent first
 *      allocation WITHOUT aborting the transaction (a plain insert+catch
 *      would poison a Postgres transaction).
 *   2. SELECT … FOR UPDATE the row, return its next_number, write back
 *      next_number+1. The row lock serialises concurrent allocators on
 *      Postgres; sqlite's single-writer database lock gives the same
 *      guarantee on the test path (its grammar ignores FOR UPDATE).
 * Numbers are therefore strictly increasing with no duplicates — a crash
 * between allocate and print can only LEAK a number (a gap), never reuse
 * one.
 */
class AllocateOrderNumberAction
{
    /**
     * @return array{number: int, formatted: string, scope: string, seq_date: string|null}|null
     *         null = numbering disabled for the company (the caller 409s).
     */
    public function handle(Device $device): ?array
    {
        $companyId = (int) $device->company_id;
        $setting = OrderNumbering::forCompany($companyId);

        if (! $setting['enabled']) {
            return null;
        }

        $branchId = $setting['scope'] === OrderNumbering::SCOPE_BRANCH
            ? (int) $device->branch_id
            : null;
        $seqDate = $setting['daily_reset'] ? now()->toDateString() : null;

        $number = DB::transaction(function () use ($companyId, $branchId, $seqDate): int {
            DB::table('pos_order_sequences')->insertOrIgnore([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'seq_date' => $seqDate,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('pos_order_sequences')
                ->where('company_id', $companyId)
                ->when(
                    $branchId === null,
                    fn ($q) => $q->whereNull('branch_id'),
                    fn ($q) => $q->where('branch_id', $branchId),
                )
                ->when(
                    $seqDate === null,
                    fn ($q) => $q->whereNull('seq_date'),
                    fn ($q) => $q->where('seq_date', $seqDate),
                )
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // Unreachable unless the row vanished mid-transaction
                // (company deleted concurrently — the FK cascades).
                throw new RuntimeException('order number sequence row could not be materialised');
            }

            DB::table('pos_order_sequences')
                ->where('id', $row->id)
                ->update([
                    'next_number' => (int) $row->next_number + 1,
                    'updated_at' => now(),
                ]);

            return (int) $row->next_number;
        });

        return [
            'number' => $number,
            'formatted' => OrderNumbering::format($setting, $number),
            'scope' => $setting['scope'],
            'seq_date' => $seqDate,
        ];
    }
}
