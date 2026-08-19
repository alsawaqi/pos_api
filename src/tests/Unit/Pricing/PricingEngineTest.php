<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\Comps;
use App\Support\Pricing\CompSelection;
use App\Support\Pricing\PricingLine;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    public function test_partial_line_comp_uses_rational_half_away_shares_and_identity(): void
    {
        $lines = [new PricingLine(unitPriceBaisas: 400, qty: 3)];

        $amount = static fn (?int $qty): int => Comps::compTotalBaisasFor(
            lines: $lines,
            lineDiscountByIndex: [0 => 200],
            giftedTotalBaisas: 0,
            subtotalBaisas: 1000,
            comp: new CompSelection(lineIndex: 0, qty: $qty, reasonId: 2),
        );

        self::assertSame(333, $amount(1));
        self::assertSame(667, $amount(2));
        self::assertSame(1000, $amount(3));
        self::assertSame(1000, $amount(null));
        self::assertSame(333, $amount(-7));
        self::assertSame(1000, $amount(999));
    }

    public function test_non_positive_line_quantity_is_guarded_before_qty_clamp(): void
    {
        self::assertSame(0, Comps::compTotalBaisasFor(
            lines: [new PricingLine(unitPriceBaisas: 1000, qty: 0)],
            lineDiscountByIndex: [0 => -1000],
            giftedTotalBaisas: 0,
            subtotalBaisas: 1000,
            comp: new CompSelection(lineIndex: 0, qty: 1),
        ));
    }

    public function test_comp_wire_rows_emit_gifts_in_line_order_then_remainder(): void
    {
        $rows = Comps::compWireRowsFor(
            giftAmountsBaisas: [2 => 800, 0 => 1200],
            compTotalBaisas: 3000,
            comp: new CompSelection(reasonId: 7),
        );

        self::assertCount(3, $rows);
        self::assertSame([0, 2, null], array_map(static fn ($row): ?int => $row->lineIndex, $rows));
        self::assertSame([1200, 800, 1000], array_map(static fn ($row): int => $row->amountBaisas, $rows));
        self::assertSame(3000, array_sum(array_map(static fn ($row): int => $row->amountBaisas, $rows)));
    }
}
