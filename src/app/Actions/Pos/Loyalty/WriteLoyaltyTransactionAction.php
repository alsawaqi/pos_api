<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.5 — the single atomic writer for the loyalty ledger (mirrors
 * pos_merchant's action of the same name, minus the portal audit log).
 *
 * Locks the account row, computes the new running balances, appends the
 * append-only transaction with its balance_after_* snapshots, then updates
 * the account's denormalised balances + last_activity_at — all in one DB
 * transaction so account ≡ Σ(transactions) can never drift. Balances may not
 * go negative (a redeem beyond balance throws → the caller's pay event fails).
 *
 * POS-driven, so recorded_by_user_id is always null here; order_id links the
 * earn/redeem to its sale.
 */
class WriteLoyaltyTransactionAction
{
    public function write(
        LoyaltyAccount $account,
        string $type,
        int $pointsDelta,
        int $stampsDelta,
        ?int $orderId = null,
        ?string $reason = null,
        ?Carbon $occurredAt = null,
    ): LoyaltyTransaction {
        if ($pointsDelta === 0 && $stampsDelta === 0) {
            throw new RuntimeException('A loyalty transaction must move points or stamps.');
        }

        return DB::transaction(function () use ($account, $type, $pointsDelta, $stampsDelta, $orderId, $reason, $occurredAt): LoyaltyTransaction {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);

            $newPoints = (int) $locked->point_balance + $pointsDelta;
            $newStamps = (int) $locked->stamp_count + $stampsDelta;
            if ($newPoints < 0) {
                throw new RuntimeException('Point balance cannot go negative.');
            }
            if ($newStamps < 0) {
                throw new RuntimeException('Stamp count cannot go negative.');
            }

            $txn = LoyaltyTransaction::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $locked->company_id,
                'loyalty_account_id' => $locked->id,
                'type' => $type,
                'points_delta' => $pointsDelta,
                'stamps_delta' => $stampsDelta,
                'balance_after_points' => $newPoints,
                'balance_after_stamps' => $newStamps,
                'reason' => $reason,
                'order_id' => $orderId,
                'recorded_by_user_id' => null,
                'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(),
            ]);

            $locked->update([
                'point_balance' => $newPoints,
                'stamp_count' => $newStamps,
                'last_activity_at' => now(),
            ]);

            return $txn;
        });
    }
}
