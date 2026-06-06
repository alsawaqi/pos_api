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
 * other lines apply to the whole grand_total regardless of tender.
 *
 * No active profile (or a profile with no share lines) ⇒ nothing is
 * recorded; the merchant simply keeps 100% (the blueprint default).
 * Idempotent: if the order already has a breakdown it is left untouched.
 */
final readonly class RecordSaleCommissionAction
{
    private const PARTY_BANK = 'bank';

    /**
     * @param  int  $cardBaisas  the card-paid amount of the sale (≤ grand_total)
     * @return array<int, int> ids of the created sale-commission rows
     */
    public function record(Order $order, Device $device, int $cardBaisas, ?int $paymentId, ?string $clientEventId): array
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
        $occurredAt = $order->closed_at ?? now();

        $rows = [];
        $sortOrder = 0;
        $allocatedBaisas = 0;

        foreach ($profile->shares as $share) {
            $percent = (float) $share->percent;
            // Bank (acquirer) cut only on card money; everyone else on the
            // whole sale. $cardBaisas ≤ $grossBaisas, so the bank slice can
            // never exceed its share of the total.
            $base = $share->party_type === self::PARTY_BANK ? $cardBaisas : $grossBaisas;
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
        // to grand_total even after rounding each share independently.
        $merchantBaisas = $grossBaisas - $allocatedBaisas;
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
