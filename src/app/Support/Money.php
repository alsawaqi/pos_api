<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The baisas⇄OMR boundary converter (the 8.1 money contract).
 *
 * Money on the device wire is INTEGER BAISAS (1 OMR = 1000 baisas) so the
 * terminal never does float math; the shared pos_* schema stores OMR as
 * decimal(12,3). Every sync handler converts at the write boundary through
 * here so the rounding rule lives in exactly one place.
 */
final class Money
{
    /** Integer baisas → decimal(12,3) OMR string, e.g. 2835 → "2.835". */
    public static function toOmr(int $baisas): string
    {
        return number_format($baisas / 1000, 3, '.', '');
    }

    /** decimal OMR (string|float) → integer baisas, e.g. "2.835" → 2835. */
    public static function toBaisas(int|float|string $omr): int
    {
        return (int) round(((float) $omr) * 1000);
    }
}
