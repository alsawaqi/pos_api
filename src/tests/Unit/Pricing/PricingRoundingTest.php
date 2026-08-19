<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\Money;
use App\Support\Pricing\OfferEngine;
use App\Support\Pricing\OfferSpec;
use App\Support\Pricing\PricingLine;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PricingRoundingTest extends TestCase
{
    public function test_odd_sixteenth_rounds_up_at_three_decimals(): void
    {
        self::assertSame(0.063, Money::roundOmr(0.0625));
    }

    public function test_bogo_allocation_exercises_the_odd_sixteenth_tie(): void
    {
        $offers = OfferEngine::evaluateOffers(
            lines: [new PricingLine(productId: 7, unitPriceBaisas: 125, qty: 2)],
            lineNetBaisas: [250],
            offers: [new OfferSpec(
                id: 1,
                name: 'Buy one, half off one',
                type: 'bogo',
                config: [
                    'buy' => ['product_ids' => [7], 'qty' => 1],
                    'get' => ['same_as_buy' => true, 'qty' => 1, 'percent_off' => 50],
                ],
            )],
            now: new DateTimeImmutable('2026-08-20T12:00:00'),
            branchId: 1,
        );

        self::assertCount(1, $offers);
        self::assertSame([0 => 63], $offers[0]->lineAmountsBaisas);
        self::assertSame(63, $offers[0]->totalBaisas());
    }

    public function test_largest_remainder_keeps_equal_weight_tie_order(): void
    {
        self::assertSame([67, 67, 66], Money::allocateBaisas([0.4, 0.4, 0.4], 200));
    }
}
