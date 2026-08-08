<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Exceptions\InsufficientLoyaltyBalanceException;
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
 * go negative: the normal writer throws, while device pay explicitly catches
 * that domain conflict and reconciles the optimistic redemption under a lock.
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
                throw new InsufficientLoyaltyBalanceException('Point balance cannot go negative.');
            }
            if ($newStamps < 0) {
                throw new InsufficientLoyaltyBalanceException('Stamp count cannot go negative.');
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

    /**
     * API-001 — settle an optimistic device redemption against the balance
     * that actually exists when the account row is locked.
     *
     * The normal {@see write()} path deliberately rejects zero-movement rows.
     * This method is the one narrow exception: when a device granted a larger
     * offline redemption than the server can honour, it appends a zero-delta
     * ADJUST marker with a stable review flag after debiting only the available
     * points/stamps. The marker is audit evidence, not customer value.
     *
     * @return array{
     *     redeem: LoyaltyTransaction|null,
     *     adjustment: LoyaltyTransaction|null,
     *     points_applied: int,
     *     stamps_applied: int,
     *     points_shortfall: int,
     *     stamps_shortfall: int
     * }
     */
    public function writeClampedRedemption(
        LoyaltyAccount $account,
        int $pointsRequested,
        int $stampsRequested,
        int $orderId,
        string $failureReason,
        ?Carbon $occurredAt = null,
    ): array {
        return DB::transaction(function () use ($account, $pointsRequested, $stampsRequested, $orderId, $failureReason, $occurredAt): array {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);

            $currentPoints = (int) $locked->point_balance;
            $currentStamps = (int) $locked->stamp_count;
            if ($currentPoints < 0 || $currentStamps < 0) {
                throw new RuntimeException('Cannot reconcile a negative loyalty account balance.');
            }

            $pointsRequested = max(0, $pointsRequested);
            $stampsRequested = max(0, $stampsRequested);
            $pointsApplied = min($pointsRequested, $currentPoints);
            $stampsApplied = min($stampsRequested, $currentStamps);
            $pointsShortfall = $pointsRequested - $pointsApplied;
            $stampsShortfall = $stampsRequested - $stampsApplied;
            $newPoints = $currentPoints - $pointsApplied;
            $newStamps = $currentStamps - $stampsApplied;
            $when = $occurredAt ?? now();

            $redeem = null;
            if ($pointsApplied > 0 || $stampsApplied > 0) {
                $redeem = LoyaltyTransaction::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'loyalty_account_id' => $locked->id,
                    'type' => LoyaltyTransaction::TYPE_REDEEM,
                    'points_delta' => -$pointsApplied,
                    'stamps_delta' => -$stampsApplied,
                    'balance_after_points' => $newPoints,
                    'balance_after_stamps' => $newStamps,
                    'reason' => 'redeemed at sale (clamped to available balance)',
                    'order_id' => $orderId,
                    'recorded_by_user_id' => null,
                    'occurred_at' => $when,
                    'created_at' => now(),
                ]);
            }

            $locked->update([
                'point_balance' => $newPoints,
                'stamp_count' => $newStamps,
                'last_activity_at' => now(),
            ]);

            $adjustment = null;
            if ($pointsShortfall > 0 || $stampsShortfall > 0) {
                $reason = sprintf(
                    '[LOYALTY_REDEMPTION_SHORTFALL][REVIEW_REQUIRED] requested points=%d stamps=%d; applied points=%d stamps=%d; shortfall points=%d stamps=%d; trigger=%s',
                    $pointsRequested,
                    $stampsRequested,
                    $pointsApplied,
                    $stampsApplied,
                    $pointsShortfall,
                    $stampsShortfall,
                    $failureReason,
                );

                $adjustment = LoyaltyTransaction::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'loyalty_account_id' => $locked->id,
                    'type' => LoyaltyTransaction::TYPE_ADJUST,
                    'points_delta' => 0,
                    'stamps_delta' => 0,
                    'balance_after_points' => $newPoints,
                    'balance_after_stamps' => $newStamps,
                    'reason' => $reason,
                    'order_id' => $orderId,
                    'recorded_by_user_id' => null,
                    'occurred_at' => $when,
                    'created_at' => now(),
                ]);
            }

            return [
                'redeem' => $redeem,
                'adjustment' => $adjustment,
                'points_applied' => $pointsApplied,
                'stamps_applied' => $stampsApplied,
                'points_shortfall' => $pointsShortfall,
                'stamps_shortfall' => $stampsShortfall,
            ];
        });
    }
}
