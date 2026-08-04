<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use App\Models\MerchantCommissionProfile;
use App\Models\Order;
use App\Models\SaleCommission;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Applies the merchant's commission profile to a paid sale and records the
 * per-party breakdown into pos_sale_commissions — one row per configured
 * share line (platform / bank / other) plus the merchant's residual.
 *
 * Called inside PayOrderHandler's transaction. Money is split in integer
 * baisas so it never drifts: each non-merchant party is rounded, and the
 * MERCHANT takes the exact remainder, so Σ(rows) == grand_total to the
 * baisa. The profile + percents are SNAPSHOT onto every row, so later
 * edits to the merchant's profile never rewrite settled history.
 *
 * Payment-method scoping: a BANK line is an acquirer fee, so it is charged
 * only on the card-paid portion of the sale ($cardBaisas) — a pure-cash
 * sale carries no bank cut and the merchant keeps that slice. Platform and
 * other lines apply to the base their `applies_to` channel selects:
 * 'all' (default) → the COLLECTED amount; 'card' → the card-paid portion;
 * 'cash_bank' → the non-card collected portion (cash + bank-POS money the
 * merchant holds). COLLECTED = grand_total minus any gifted portion —
 * Phase D4: a gift tender is money never collected, so nobody takes a cut
 * of it; a fully gifted order records NO rows at all.
 *
 * No active profile (or a profile with no share lines) ⇒ nothing is
 * recorded; the merchant simply keeps 100% (the blueprint default).
 * Idempotent: if the order already has a breakdown it is left untouched.
 *
 * CHANNEL SPLITTING (mixed-tender apportionment): every row is stamped
 * with the money CHANNEL it belongs to — 'card' (the platform holds this
 * money; paid to the merchant via payouts) or 'cash_bank' (the merchant
 * already holds it; the platform bills its cut via invoices). A share
 * line lands in the channel its base selects; an 'all' line on a MIXED
 * order splits into one row per non-empty channel; the merchant residual
 * is computed PER CHANNEL. A pure order therefore emits exactly the same
 * rows as before, just channel-stamped. This is what lets the payout
 * claim only card-channel residuals (money the platform actually holds)
 * — the fix for the mixed-order leak where the whole-order residual was
 * paid out while the merchant already held the cash slice in the drawer.
 *
 * Invariants: Σ(rows.commission_amount) == COLLECTED, and per channel
 * Σ(channel rows) == that channel's collected slice (gross_amount still
 * snapshots the full grand_total on every row).
 */
final readonly class RecordSaleCommissionAction
{
    private const PARTY_BANK = 'bank';

    private const APPLIES_ALL = 'all';

    private const APPLIES_CARD = 'card';

    private const APPLIES_CASH_BANK = 'cash_bank';

    /**
     * @param  int  $cardBaisas  the card-paid amount of the sale (≤ grand_total)
     * @param  int  $giftBaisas  the gifted (never-collected) amount (≤ grand_total)
     * @return array<int, int> ids of the created sale-commission rows
     */
    public function record(Order $order, Device $device, int $cardBaisas, int $giftBaisas, ?int $paymentId, ?string $clientEventId): array
    {
        // Idempotency: one breakdown per order, ever.
        if (SaleCommission::query()->where('order_id', $order->id)->exists()) {
            return [];
        }

        $profile = MerchantCommissionProfile::query()
            ->where('company_id', $order->company_id)
            ->where('is_active', true)
            ->with('shares')
            ->first();

        if ($profile === null || $profile->shares->isEmpty()) {
            return [];
        }

        $grossBaisas = Money::toBaisas($order->grand_total);
        // Phase D4 — only money actually COLLECTED is split. A fully gifted
        // sale (collected == 0) records nothing: there is nothing to share.
        $collectedBaisas = max(0, $grossBaisas - max(0, $giftBaisas));
        if ($collectedBaisas === 0) {
            return [];
        }
        $occurredAt = $order->closed_at ?? now();

        // The two money channels of this order. $cardBaisas ≤ $collectedBaisas
        // (a gift tender is never a card tender), so the slices partition the
        // collected amount exactly.
        $cardSlice = min($cardBaisas, $collectedBaisas);
        $cashSlice = $collectedBaisas - $cardSlice;

        $rows = [];
        $sortOrder = 0;
        $allocatedByChannel = [self::APPLIES_CARD => 0, self::APPLIES_CASH_BANK => 0];

        $emit = function (object $share, string $channel, int $base) use (&$rows, &$sortOrder, &$allocatedByChannel): void {
            $amountBaisas = (int) round($base * (float) $share->percent / 100);
            $allocatedByChannel[$channel] += $amountBaisas;
            $rows[] = [
                'party_type' => $share->party_type,
                'party_label' => $share->label,
                'channel' => $channel,
                'percent' => (float) $share->percent,
                'amount_baisas' => $amountBaisas,
                'sort_order' => $sortOrder++,
            ];
        };

        foreach ($profile->shares as $share) {
            // Bank (acquirer) cut only on card money. Everyone else on the base
            // their channel selects: 'all' → collected, 'card' → card money,
            // 'cash_bank' → non-card collected (cash + bank-POS). Null-safe on
            // applies_to (a missing attribute reads as 'all' — prior
            // behaviour); the channel COLUMN has its own hasColumn fallback.
            $appliesTo = (string) ($share->applies_to ?? self::APPLIES_ALL);
            if ($share->party_type === self::PARTY_BANK || $appliesTo === self::APPLIES_CARD) {
                // Charged on card money; a 0-amount bank row on a cash sale is
                // deliberate (prior behaviour — the row documents the 0 cut).
                $emit($share, self::APPLIES_CARD, $cardSlice);
            } elseif ($appliesTo === self::APPLIES_CASH_BANK) {
                $emit($share, self::APPLIES_CASH_BANK, $cashSlice);
            } elseif ($cardSlice > 0 && $cashSlice > 0) {
                // 'all' on a MIXED order: one row per channel, each on its
                // slice, so each channel's books balance independently.
                $emit($share, self::APPLIES_CARD, $cardSlice);
                $emit($share, self::APPLIES_CASH_BANK, $cashSlice);
            } else {
                // 'all' on a pure order: single row in the only channel —
                // identical to the pre-split behaviour, channel-stamped.
                $emit($share, $cardSlice > 0 ? self::APPLIES_CARD : self::APPLIES_CASH_BANK, $collectedBaisas);
            }
        }

        // The merchant takes the exact remainder PER CHANNEL — guarantees
        // Σ(channel rows) == that channel's collected slice even after
        // rounding each share independently, which is what payouts (card
        // channel) and invoices (cash channel) rely on. A pure order emits
        // one merchant row, exactly as before.
        $merchantChannels = [];
        if ($cardSlice > 0) {
            $merchantChannels[] = [self::APPLIES_CARD, $cardSlice - $allocatedByChannel[self::APPLIES_CARD]];
        }
        if ($cashSlice > 0) {
            $merchantChannels[] = [self::APPLIES_CASH_BANK, $cashSlice - $allocatedByChannel[self::APPLIES_CASH_BANK]];
        }
        foreach ($merchantChannels as [$channel, $merchantBaisas]) {
            $rows[] = [
                'party_type' => 'merchant',
                'party_label' => 'Merchant',
                'channel' => $channel,
                'percent' => (float) $profile->merchant_percent,
                'amount_baisas' => $merchantBaisas,
                'sort_order' => $sortOrder++,
            ];
        }

        $ids = [];
        foreach ($rows as $row) {
            $saleCommission = SaleCommission::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'device_id' => $device->getKey(),
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'commission_profile_id' => $profile->id,
                'party_type' => $row['party_type'],
                'party_label' => $row['party_label'],
                ...(self::channelColumnExists() ? ['channel' => $row['channel']] : []),
                'percent' => $row['percent'],
                'gross_amount' => Money::toOmr($grossBaisas),
                'commission_amount' => Money::toOmr($row['amount_baisas']),
                'sort_order' => $row['sort_order'],
                'client_event_id' => $clientEventId,
                'occurred_at' => $occurredAt,
            ]);

            $ids[] = (int) $saleCommission->id;
        }

        return $ids;
    }
    /**
     * Deploy-window safety: the shared-DB `channel` column ships in a
     * pos_admin migration. If this app ever runs against a DB where that
     * migration has not landed yet, recording must fall back to legacy
     * 'all' rows (column default) instead of failing every paid order.
     * Cached per process; refreshed on deploy restart.
     */
    private static function channelColumnExists(): bool
    {
        static $exists = null;

        return $exists ??= \Illuminate\Support\Facades\Schema::hasColumn('pos_sale_commissions', 'channel');
    }
}
