<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\AppliedOfferResult;
use App\Support\Pricing\LineDiscountResult;
use App\Support\Pricing\Split;
use App\Support\Pricing\TaxLineResult;
use App\Support\Pricing\Totals;
use App\Support\Pricing\VectorLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingGoldenReplayTest extends TestCase
{
    /** @param array<string, mixed> $vector */
    #[DataProvider('pricing_vectors')]
    public function test_pricing_vector_replays_every_expected_field(array $vector): void
    {
        $result = Totals::priceOrder(VectorLoader::inputFromArray($vector['input']));
        $expected = $vector['expected'];

        foreach ([
            'rawSubtotalBaisas',
            'discountTotalBaisas',
            'orderDiscountBaisas',
            'lineDiscountTotalBaisas',
            'offerDiscountTotalBaisas',
            'subtotalBaisas',
            'giftedTotalBaisas',
            'managerCompBaisas',
            'compTotalBaisas',
            'taxedBaseBaisas',
            'taxTotalBaisas',
            'grandTotalBaisas',
        ] as $field) {
            self::assertSame($expected[$field], $result->{$field}, $field);
        }

        $expectedLineDiscounts = $expected['lineDiscounts'] ?? [];
        self::assertCount(count($expectedLineDiscounts), $result->lineDiscounts, 'lineDiscounts count');
        foreach ($expectedLineDiscounts as $index => $row) {
            $actual = $result->lineDiscounts[$index];
            self::assertInstanceOf(LineDiscountResult::class, $actual);
            self::assertSame($row['lineIndex'], $actual->lineIndex, "lineDiscounts[$index].lineIndex");
            self::assertSame($row['amountBaisas'], $actual->amountBaisas, "lineDiscounts[$index].amountBaisas");
            self::assertSame($row['ruleId'], $actual->ruleId, "lineDiscounts[$index].ruleId");
            self::assertSame($row['amountType'], $actual->amountType, "lineDiscounts[$index].amountType");
        }

        $expectedOffers = $expected['appliedOffers'] ?? [];
        self::assertCount(count($expectedOffers), $result->appliedOffers, 'appliedOffers count');
        foreach ($expectedOffers as $index => $row) {
            $actual = $result->appliedOffers[$index];
            self::assertInstanceOf(AppliedOfferResult::class, $actual);
            self::assertSame($row['offerId'], $actual->offerId, "appliedOffers[$index].offerId");
            self::assertSame($row['applications'], $actual->applications, "appliedOffers[$index].applications");
            self::assertSame($row['orderAmountBaisas'] ?? 0, $actual->orderAmountBaisas, "appliedOffers[$index].orderAmountBaisas");
            $expectedAmounts = [];
            foreach ($row['lineAmountsBaisas'] ?? [] as $lineIndex => $amount) {
                $expectedAmounts[(int) $lineIndex] = (int) $amount;
            }
            self::assertSame($expectedAmounts, $actual->lineAmountsBaisas, "appliedOffers[$index].lineAmountsBaisas");
        }

        if (array_key_exists('giftAmountsBaisas', $expected)) {
            $expectedGifts = [];
            foreach ($expected['giftAmountsBaisas'] as $lineIndex => $amount) {
                $expectedGifts[(int) $lineIndex] = (int) $amount;
            }
            self::assertSame($expectedGifts, $result->giftAmountsBaisas, 'giftAmountsBaisas');
        }

        $expectedTaxes = [];
        foreach ($expected['taxLines'] ?? [] as $row) {
            $expectedTaxes[$row['name']] = $row['amountBaisas'];
        }
        $actualTaxes = [];
        foreach ($result->taxLines as $row) {
            self::assertInstanceOf(TaxLineResult::class, $row);
            $actualTaxes[$row->name] = $row->amountBaisas;
        }
        self::assertSame($expectedTaxes, $actualTaxes, 'taxLines');

        self::assertSame(
            $result->grandTotalBaisas,
            $result->rawSubtotalBaisas - $result->discountTotalBaisas - $result->compTotalBaisas + $result->taxTotalBaisas,
            'exact zero-baisa invariant',
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('split_cases')]
    public function test_split_vector_replays_each_case(array $case): void
    {
        if (isset($case['equalShare'])) {
            $input = $case['equalShare'];
            self::assertSame($input['expected'], Split::equalShareBaisas($input['grand'], $input['count']), $case['case']);
        }
        if (isset($case['remainderShare'])) {
            $input = $case['remainderShare'];
            self::assertSame($input['expected'], Split::remainderShareBaisas($input['grand'], $input['paidBase']), $case['case']);
        }
        if (isset($case['plan'])) {
            $input = $case['plan'];
            $actual = Split::validateSplitPlan($input['shares'], $input['grand']);
            self::assertSame($input['expected'], $actual, $case['case']);
            if ($actual !== null) {
                self::assertTrue(Split::splitPlanMatchesTotal($actual, $input['grand']), $case['case'].' closes to total');
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pricing_vectors(): iterable
    {
        foreach (self::fixtureFiles() as $file) {
            $vector = self::decode($file);
            if (($vector['kind'] ?? null) !== 'split') {
                yield basename($file, '.json') => [$vector];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function split_cases(): iterable
    {
        $vector = self::decode(self::fixtureDirectory().'/split_shares.json');
        foreach ($vector['cases'] as $case) {
            yield $case['case'] => [$case];
        }
    }

    /** @return list<string> */
    private static function fixtureFiles(): array
    {
        $files = glob(self::fixtureDirectory().'/*.json');
        self::assertIsArray($files);
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }

    private static function fixtureDirectory(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/pricing_goldens/v0.2.0';
    }

    /** @return array<string, mixed> */
    private static function decode(string $file): array
    {
        $json = file_get_contents($file);
        self::assertNotFalse($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
