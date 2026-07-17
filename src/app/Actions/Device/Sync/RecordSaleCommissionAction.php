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
 * Invariant: Σ(rows.commission_amount) == COLLECTED (== grand_total when
 * nothing was gifted; gross_amount still snapshots the full grand_total).
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

        $rows = [];
        $sortOrder = 0;
        $allocatedBaisas = 0;

        foreach ($profile->shares as $share) {
            $percent = (float) $share->percent;
            // Bank (acquirer) cut only on card money. Everyone else on the base
            // their channel selects: 'all' → collected, 'card' → card money,
            // 'cash_bank' → non-card collected (cash + bank-POS). Null-safe so
            // this deploys cleanly before the pos_admin migration adds the
            // column (a missing attribute reads as 'all' — prior behaviour).
            // $cardBaisas ≤ $collectedBaisas (a gift tender is never a card
            // tender), so no slice can exceed its share of the total.
            $appliesTo = (string) ($share->applies_to ?? self::APPLIES_ALL);
            if ($share->party_type === self::PARTY_BANK || $appliesTo === self::APPLIES_CARD) {
                $base = $cardBaisas;
            } elseif ($appliesTo === self::APPLIES_CASH_BANK) {
                $base = max(0, $collectedBaisas - $cardBaisas);
            } else {
                $base = $collectedBaisas;
            }
            $amountBaisas = (int) round($base * $percent / 100);
            $allocatedBaisas += $amountBaisas;

            $rows[] = [
                'party_type' => $share->party_type,
                'party_label' => $share->label,
                'percent' => $percent,
                'amount_baisas' => $amountBaisas,
                'sort_order' => $sortOrder++,
            ];
        }

        // The merchant takes the exact remainder — guarantees the rows sum
        // to the COLLECTED amount even after rounding each share
        // independently (== grand_total when nothing was gifted).
        $merchantBaisas = $collectedBaisas - $allocatedBaisas;
        $rows[] = [
            'party_type' => 'merchant',
            'party_label' => 'Merchant',
            'percent' => (float) $profile->merchant_percent,
            'amount_baisas' => $merchantBaisas,
            'sort_order' => $sortOrder,
        ];

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
}
