<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\ComboAllocation;
use PHPUnit\Framework\TestCase;

/**
 * LAUNCH combo add-on (owner decision 6) — the profit split against the
 * golden vectors shared with mithqal_pricing (tests/Fixtures/
 * combo_allocation_vectors.json): every split adds up exactly.
 */
final class ComboAllocationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/combo_allocation_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_split_vectors(): void
    {
        foreach (self::vectors()['split'] as $vector) {
            $shares = ComboAllocation::split($vector['amount'], $vector['weights']);
            $this->assertSame($vector['expected'], $shares, $vector['name']);
            $this->assertSame($vector['amount'], array_sum($shares), $vector['name']);
        }
    }

    public function test_the_line_vectors(): void
    {
        foreach (self::vectors()['line'] as $vector) {
            $shares = ComboAllocation::forLine($vector['unit_price'], $vector['qty'], $vector['line_total'], $vector['children']);
            $this->assertSame($vector['expected'], $shares, $vector['name']);
            $this->assertSame($vector['line_total'], array_sum($shares), $vector['name']);
        }
    }

    public function test_random_splits_always_add_up_and_never_go_below_zero(): void
    {
        mt_srand(20261007);
        for ($i = 0; $i < 500; $i++) {
            $weights = array_map(static fn (): int => mt_rand(0, 5) === 0 ? mt_rand(0, 3) : mt_rand(1, 20000), range(1, mt_rand(1, 9)));
            $amount = mt_rand(0, 50000);
            $shares = ComboAllocation::split($amount, $weights);
            $this->assertSame($amount, array_sum($shares));
            $this->assertGreaterThanOrEqual(0, min($shares));
        }
    }
}
