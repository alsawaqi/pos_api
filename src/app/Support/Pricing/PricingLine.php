<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class PricingLine
{
    public function __construct(
        public int $unitPriceBaisas,
        public int $qty,
        public ?int $productId = null,
        public ?int $categoryId = null,
        public bool $gifted = false,
        public string $bundleKey = '',
    ) {}

    public function lineTotalBaisas(): int
    {
        return $this->unitPriceBaisas * $this->qty;
    }

    public function lineTotalOmr(): float
    {
        return Money::baisasToOmr($this->lineTotalBaisas());
    }
}
