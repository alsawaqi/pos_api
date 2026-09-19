<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderDiscount;
use App\Models\QrOrderRound;
use App\Support\Money;

/** Frozen rounds remain intact. Only this action calculates the bill header. */
final class RefreshQrOrderTotalsAction
{
    public const MANUAL_TYPES = ['table_manual_percent', 'table_manual_fixed', 'table_manual_rule',
        'table_manual_reversal', 'table_manual_clamp'];

    public function manualRows(Order $order)
    {
        return OrderDiscount::query()->where('order_id', $order->id)->whereIn('amount_type_snapshot', self::MANUAL_TYPES);
    }

    public function amounts(Order $order): array
    {
        $totals = QrOrderRound::query()->where('order_id', $order->id)->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->selectRaw('COALESCE(SUM(subtotal_baisas), 0) AS subtotal_baisas, '.
                'COALESCE(SUM(tax_baisas), 0) AS tax_baisas, COALESCE(SUM(total_baisas), 0) AS total_baisas')->first();

        return ['subtotal' => (int) $totals->subtotal_baisas, 'tax' => (int) $totals->tax_baisas,
            'total' => (int) $totals->total_baisas,
            'manual' => $this->manualRows($order)->get()->sum(fn ($row): int => Money::toBaisas($row->amount)),
            'comp' => OrderComp::query()->where('order_id', $order->id)->get()->sum(fn ($row): int => Money::toBaisas($row->amount))];
    }

    /** Read-only calculation also used by charge integrity checks. No clamping here. */
    public function header(array $amounts): array
    {
        ['subtotal' => $s, 'tax' => $t, 'total' => $g, 'manual' => $m, 'comp' => $c] = $amounts;
        $roundDiscount = max(0, $s + $t - $g);
        $net = $s - $roundDiscount;
        $base = $net - $m - $c;
        // Preserve the old no-adjustment branch exactly, including empty bills.
        $tax = $m === 0 && $c === 0 ? $t : ($net === 0 ? 0 : (int) round($t * $base / $net));

        return ['subtotal' => Money::toOmr($s), 'discount_total' => Money::toOmr($roundDiscount + $m),
            'comp_total' => Money::toOmr($c), 'tax_total' => Money::toOmr($tax),
            'grand_total' => Money::toOmr($m === 0 && $c === 0 ? $g : $base + $tax)];
    }

    public function matchesHeader(Order $order, ?array $amounts = null): bool
    {
        $a = $amounts ?? $this->amounts($order);
        if (min($a) < 0 || max(0, $a['total'] - $a['tax'] - 1) < $a['manual'] + $a['comp']) {
            return false;
        }
        foreach ($this->header($a) as $key => $value) {
            if (Money::toBaisas($order->$key ?? 0) !== Money::toBaisas($value)) {
                return false;
            }
        }

        return true;
    }

    /** Append a reversal carrying the original attribution; never update audit rows. */
    public function reverseDiscount(Order $order, int $amount, string $type = 'table_manual_reversal'): void
    {
        if ($amount <= 0) {
            return;
        }
        $source = $this->manualRows($order)->where('amount', '>', 0)->orderByDesc('id')->firstOrFail();
        $row = $source->replicate();
        $row->amount = Money::toOmr(-$amount);
        $row->amount_type_snapshot = $type;
        $row->applied_at = now();
        $row->save();
    }

    public function reverseComp(Order $order, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $source = OrderComp::query()->where('order_id', $order->id)->where('amount', '>', 0)->orderByDesc('id')->firstOrFail();
        $row = $source->replicate();
        $row->amount = Money::toOmr(-$amount);
        $row->applied_at = now();
        $row->save();
    }

    public function handle(Order $order): void
    {
        $a = $this->amounts($order);
        $excess = $a['manual'] + $a['comp'] - max(0, $a['total'] - $a['tax'] - 1);
        if ($excess > 0) {
            $discount = min($a['manual'], $excess);
            $this->reverseDiscount($order, $discount, 'table_manual_clamp');
            $a['manual'] -= $discount;
            $excess -= $discount;
            $this->reverseComp($order, $excess);
            $a['comp'] -= $excess;
        }
        $header = $this->header($a);
        // Old no-comp refresh did not write this field. Keep it byte-equivalent.
        if ($a['comp'] === 0 && Money::toBaisas($order->comp_total ?? 0) === 0) {
            unset($header['comp_total']);
        }
        $order->update($header);
    }
}
