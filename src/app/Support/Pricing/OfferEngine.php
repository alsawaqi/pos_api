<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final class OfferEngine
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  list<int>  $lineNetBaisas
     * @param  list<OfferSpec>  $offers
     * @return list<AppliedOfferResult>
     */
    public static function evaluateOffers(
        array $lines,
        array $lineNetBaisas,
        array $offers,
        DateTimeImmutable $now,
        int $branchId,
    ): array {
        $lineNetOmr = array_map(Money::baisasToOmr(...), $lineNetBaisas);
        $units = [];
        foreach ($lines as $lineIndex => $line) {
            if ($line->gifted || $line->bundleKey !== '' || $line->qty <= 0) {
                continue;
            }
            $perUnit = $lineNetOmr[$lineIndex] / $line->qty;
            for ($unit = 0; $unit < $line->qty; $unit++) {
                $units[] = new OfferUnit($lineIndex, $line->productId, $line->categoryId, $perUnit);
            }
        }

        $sorted = $offers;
        usort($sorted, static fn (OfferSpec $a, OfferSpec $b): int => $a->id <=> $b->id);
        $applied = [];
        foreach ($sorted as $offer) {
            if (! Applicability::offerAppliesAt($offer, $now, $branchId)) {
                continue;
            }
            $result = match ($offer->type) {
                'bogo' => self::applyBogo($offer, $units),
                'multi_buy' => self::applyMultiBuy($offer, $units),
                'cheapest_free' => self::applyCheapestFree($offer, $units),
                'spend_get' => self::applySpendGet($offer, $units, $lines, $lineNetOmr),
                'bundle' => self::applyBundles($offer, $lines, $lineNetOmr),
                default => null,
            };
            if ($result !== null && self::totalOmr($result) > 0) {
                $applied[] = self::toResult($offer, $result);
            }
        }

        return $applied;
    }

    /**
     * @param  list<OfferUnit>  $units
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function applyBogo(OfferSpec $offer, array $units): ?array
    {
        $buyConfig = is_array($offer->config['buy'] ?? null) ? $offer->config['buy'] : [];
        $getConfig = is_array($offer->config['get'] ?? null) ? $offer->config['get'] : [];
        $buy = new OfferSelector($buyConfig);
        $get = ($getConfig['same_as_buy'] ?? false) === true ? $buy : new OfferSelector($getConfig);
        if ($buy->isEmpty() || $get->isEmpty()) {
            return null;
        }

        $buyQty = min(max(Combo::intOf($buyConfig['qty'] ?? null, 1), 1), 999);
        $getQty = min(max(Combo::intOf($getConfig['qty'] ?? null, 1), 1), 999);
        $percent = min(max(Combo::intOf($getConfig['percent_off'] ?? null, 100), 1), 100);
        $amounts = [];
        $applications = 0;
        while ($offer->maxPerOrder === null || $applications < $offer->maxPerOrder) {
            $buyPool = self::freeUnits($units, $buy);
            usort($buyPool, static fn (OfferUnit $a, OfferUnit $b): int => $b->value <=> $a->value);
            if (count($buyPool) < $buyQty) {
                break;
            }
            $buySet = array_slice($buyPool, 0, $buyQty);
            foreach ($buySet as $unit) {
                $unit->consumed = true;
            }

            $getPool = self::freeUnits($units, $get);
            usort($getPool, static fn (OfferUnit $a, OfferUnit $b): int => $a->value <=> $b->value);
            if (count($getPool) < $getQty) {
                foreach ($buySet as $unit) {
                    $unit->consumed = false;
                }
                break;
            }
            foreach (array_slice($getPool, 0, $getQty) as $unit) {
                $unit->consumed = true;
                self::take($amounts, $unit, Money::roundOmr($unit->value * $percent / 100));
            }
            $applications++;
        }

        return $applications === 0 ? null : self::applied($amounts, 0.0, $applications);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function applyMultiBuy(OfferSpec $offer, array $units): ?array
    {
        $selector = new OfferSelector($offer->config);
        $qty = Combo::intOf($offer->config['qty'] ?? null);
        $price = Combo::omrOf($offer->config['price_baisas'] ?? null);
        if ($selector->isEmpty() || $qty < 2 || $price <= 0) {
            return null;
        }

        $amounts = [];
        $applications = 0;
        while ($offer->maxPerOrder === null || $applications < $offer->maxPerOrder) {
            $pool = self::freeUnits($units, $selector);
            usort($pool, static fn (OfferUnit $a, OfferUnit $b): int => $b->value <=> $a->value);
            if (count($pool) < $qty) {
                break;
            }
            $set = array_slice($pool, 0, $qty);
            $setValue = Money::roundOmr(array_reduce(
                $set,
                static fn (float $sum, OfferUnit $unit): float => $sum + $unit->value,
                0.0,
            ));
            $discount = Money::roundOmr(min(max($setValue - $price, 0.0), $setValue));
            if ($discount <= 0) {
                break;
            }
            foreach ($set as $unit) {
                $unit->consumed = true;
            }
            self::allocateToUnits($set, $discount, $amounts);
            $applications++;
        }

        return $applications === 0 ? null : self::applied($amounts, 0.0, $applications);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function applyCheapestFree(OfferSpec $offer, array $units): ?array
    {
        $selector = new OfferSelector($offer->config);
        $qty = Combo::intOf($offer->config['qty'] ?? null);
        $freeCount = min(max(Combo::intOf($offer->config['free_count'] ?? null, 1), 1), 999);
        if ($selector->isEmpty() || $qty < 2 || $freeCount >= $qty) {
            return null;
        }

        $amounts = [];
        $applications = 0;
        while ($offer->maxPerOrder === null || $applications < $offer->maxPerOrder) {
            $pool = self::freeUnits($units, $selector);
            usort($pool, static fn (OfferUnit $a, OfferUnit $b): int => $b->value <=> $a->value);
            if (count($pool) < $qty) {
                break;
            }
            $set = array_slice($pool, 0, $qty);
            foreach ($set as $unit) {
                $unit->consumed = true;
            }
            usort($set, static fn (OfferUnit $a, OfferUnit $b): int => $a->value <=> $b->value);
            foreach (array_slice($set, 0, $freeCount) as $unit) {
                self::take($amounts, $unit, $unit->value);
            }
            $applications++;
        }

        return $applications === 0 ? null : self::applied($amounts, 0.0, $applications);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @param  list<PricingLine>  $lines
     * @param  list<float>  $lineNetOmr
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function applySpendGet(OfferSpec $offer, array $units, array $lines, array $lineNetOmr): ?array
    {
        $minimum = Combo::omrOf($offer->config['min_subtotal_baisas'] ?? null);
        if ($minimum <= 0) {
            return null;
        }

        $eligible = 0.0;
        foreach ($lines as $index => $line) {
            if (! $line->gifted) {
                $eligible += $lineNetOmr[$index];
            }
        }
        if (Money::roundOmr($eligible) + 0.0005 < $minimum) {
            return null;
        }

        return match (($offer->config['reward_type'] ?? null)) {
            'percent_off' => self::spendPercent($offer, $eligible),
            'fixed_off' => self::spendFixed($offer, $eligible),
            'free_product' => self::spendFreeProduct($offer, $units),
            default => null,
        };
    }

    /** @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null */
    private static function spendPercent(OfferSpec $offer, float $eligible): ?array
    {
        $raw = $offer->config['reward_value'] ?? null;
        $percent = is_int($raw) || is_float($raw) ? min(max((float) $raw, 0.0), 100.0) : 0.0;

        return $percent <= 0 ? null : self::applied([], Money::roundOmr($eligible * $percent / 100), 1);
    }

    /** @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null */
    private static function spendFixed(OfferSpec $offer, float $eligible): ?array
    {
        $fixed = Combo::omrOf($offer->config['reward_value'] ?? null);

        return $fixed <= 0 ? null : self::applied([], Money::roundOmr(min(max($fixed, 0.0), $eligible)), 1);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function spendFreeProduct(OfferSpec $offer, array $units): ?array
    {
        $productId = Combo::intOf($offer->config['reward_product_id'] ?? null, -1);
        if ($productId < 0) {
            return null;
        }
        $pool = array_values(array_filter(
            $units,
            static fn (OfferUnit $unit): bool => ! $unit->consumed && $unit->productId === $productId,
        ));
        usort($pool, static fn (OfferUnit $a, OfferUnit $b): int => $a->value <=> $b->value);
        if ($pool === []) {
            return null;
        }
        $unit = $pool[0];
        $unit->consumed = true;

        return self::applied([$unit->lineIndex => Money::roundOmr($unit->value)], 0.0, 1);
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  list<float>  $lineNetOmr
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}|null
     */
    private static function applyBundles(OfferSpec $offer, array $lines, array $lineNetOmr): ?array
    {
        $price = Combo::omrOf($offer->config['price_baisas'] ?? null);
        $groups = array_values(array_filter(
            is_array($offer->config['groups'] ?? null) ? $offer->config['groups'] : [],
            is_array(...),
        ));
        if ($price <= 0 || $groups === []) {
            return null;
        }

        $instances = Combo::bundleInstances($offer, $lines);
        if ($instances === []) {
            return null;
        }

        $amounts = [];
        $applications = 0;
        foreach ($instances as $lineIndexes) {
            if ($offer->maxPerOrder !== null && $applications >= $offer->maxPerOrder) {
                continue;
            }
            if (! Combo::bundleInstanceIntact($lines, $lineIndexes, $groups)) {
                continue;
            }

            $setValue = Money::roundOmr(array_reduce(
                $lineIndexes,
                static fn (float $sum, int $index): float => $sum + $lineNetOmr[$index],
                0.0,
            ));
            $discount = Money::roundOmr(min(max($setValue - $price, 0.0), $setValue));
            if ($discount <= 0) {
                continue;
            }
            $lineUnits = array_map(
                static fn (int $index): OfferUnit => new OfferUnit($index, null, null, $lineNetOmr[$index]),
                $lineIndexes,
            );
            self::allocateToUnits($lineUnits, $discount, $amounts);
            $applications++;
        }

        return $applications === 0 ? null : self::applied($amounts, 0.0, $applications);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @return list<OfferUnit>
     */
    private static function freeUnits(array $units, OfferSelector $selector): array
    {
        return array_values(array_filter(
            $units,
            static fn (OfferUnit $unit): bool => ! $unit->consumed && $selector->matches($unit),
        ));
    }

    /** @param array<int, float> $amounts */
    private static function take(array &$amounts, OfferUnit $unit, float $amount): void
    {
        $amounts[$unit->lineIndex] = Money::roundOmr(($amounts[$unit->lineIndex] ?? 0.0) + $amount);
    }

    /**
     * @param  list<OfferUnit>  $units
     * @param  array<int, float>  $amounts
     */
    private static function allocateToUnits(array $units, float $discount, array &$amounts): void
    {
        $discountBaisas = (int) round($discount * 1000);
        $parts = Money::allocateBaisas(
            array_map(static fn (OfferUnit $unit): float => $unit->value, $units),
            $discountBaisas,
        );
        foreach ($units as $index => $unit) {
            if ($parts[$index] > 0) {
                self::take($amounts, $unit, $parts[$index] / 1000.0);
            }
        }
    }

    /**
     * @param  array<int, float>  $lineAmounts
     * @return array{lineAmounts: array<int, float>, orderAmount: float, applications: int}
     */
    private static function applied(array $lineAmounts, float $orderAmount, int $applications): array
    {
        return compact('lineAmounts', 'orderAmount', 'applications');
    }

    /** @param array{lineAmounts: array<int, float>, orderAmount: float, applications: int} $applied */
    private static function totalOmr(array $applied): float
    {
        return Money::roundOmr(array_sum($applied['lineAmounts']) + $applied['orderAmount']);
    }

    /** @param array{lineAmounts: array<int, float>, orderAmount: float, applications: int} $applied */
    private static function toResult(OfferSpec $offer, array $applied): AppliedOfferResult
    {
        $lineAmountsBaisas = [];
        foreach ($applied['lineAmounts'] as $lineIndex => $amount) {
            $lineAmountsBaisas[$lineIndex] = Money::omrToBaisas($amount);
        }

        return new AppliedOfferResult(
            offerId: $offer->id,
            name: $offer->name,
            nameAr: $offer->nameAr,
            lineAmountsBaisas: $lineAmountsBaisas,
            orderAmountBaisas: Money::omrToBaisas($applied['orderAmount']),
            applications: $applied['applications'],
        );
    }
}

final class OfferUnit
{
    public function __construct(
        public readonly int $lineIndex,
        public readonly ?int $productId,
        public readonly ?int $categoryId,
        public readonly float $value,
        public bool $consumed = false,
    ) {}
}

final readonly class OfferSelector
{
    /** @var array<int, true> */
    private array $productIds;

    /** @var array<int, true> */
    private array $categoryIds;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->productIds = Combo::intSetOf($config['product_ids'] ?? null);
        $this->categoryIds = Combo::intSetOf($config['category_ids'] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->productIds === [] && $this->categoryIds === [];
    }

    public function matches(OfferUnit $unit): bool
    {
        return ($unit->productId !== null && isset($this->productIds[$unit->productId]))
            || ($unit->categoryId !== null && isset($this->categoryIds[$unit->categoryId]));
    }
}
