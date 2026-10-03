<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * LAUNCH-P4 — the bill identities in both tax modes, for every server path
 * that adds, reduces or re-checks frozen bill amounts (QR and table rounds,
 * the bill header, cancellations, combines).
 *
 *   exclusive (prices_include_tax false): total = taxed base + tax
 *   inclusive (prices_include_tax true):  total = taxed base (tax inside)
 *
 * With subtotal the gross line sum: discount = subtotal + tax − total
 * (exclusive) or subtotal − total (inclusive); the pre-tax net the
 * adjustments may not exceed is total − tax (exclusive) or total (inclusive,
 * where the tax is part of what the customer is charged).
 */
final class BillMoney
{
    public static function total(int $taxedBaseBaisas, int $taxBaisas, bool $inclusive): int
    {
        return $inclusive ? $taxedBaseBaisas : $taxedBaseBaisas + $taxBaisas;
    }

    public static function discount(int $subtotalBaisas, int $taxBaisas, int $totalBaisas, bool $inclusive): int
    {
        return $inclusive ? $subtotalBaisas - $totalBaisas : $subtotalBaisas + $taxBaisas - $totalBaisas;
    }

    public static function net(int $totalBaisas, int $taxBaisas, bool $inclusive): int
    {
        return $inclusive ? $totalBaisas : $totalBaisas - $taxBaisas;
    }
}
