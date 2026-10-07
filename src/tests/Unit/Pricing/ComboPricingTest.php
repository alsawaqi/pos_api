<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\ComboPricing;
use PHPUnit\Framework\TestCase;

/**
 * LAUNCH combo add-on, fix order 1 (C-9, tester call 4) — the unit price of a
 * line against the golden vectors shared with mithqal_pricing
 * (tests/Fixtures/combo_pricing_vectors.json): every item inside a combo or
 * meal and every line stay at 0 or more.
 */
final class ComboPricingTest extends TestCase
{
    public function test_the_pricing_vectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/combo_pricing_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($vectors['cases'] as $case) {
            $items = array_map(static fn (array $item): int => ComboPricing::item($item['qty'], $item['extra'], $item['addons']), $case['items']);
            $this->assertSame($case['expected'], ComboPricing::unit($case['kind'], $case['base'], $case['base_addons'], $case['meal_price'], $items), $case['name']);
        }
    }
}
