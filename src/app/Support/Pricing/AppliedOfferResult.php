<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class AppliedOfferResult
{
    /** @param array<int, int> $lineAmountsBaisas */
    public function __construct(
        public int $offerId,
        public string $name,
        public ?string $nameAr = null,
        public array $lineAmountsBaisas = [],
        public int $orderAmountBaisas = 0,
        public int $applications = 1,
    ) {}

    public function totalBaisas(): int
    {
        return array_sum($this->lineAmountsBaisas) + $this->orderAmountBaisas;
    }
}
