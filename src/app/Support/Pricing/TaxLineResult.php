<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class TaxLineResult
{
    public function __construct(
        public string $name,
        public float $ratePercent,
        public int $amountBaisas,
        public ?string $nameAr = null,
    ) {}
}
