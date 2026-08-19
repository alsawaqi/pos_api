<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class TaxSpec
{
    public function __construct(
        public string $name,
        public float $ratePercent,
        public ?string $nameAr = null,
    ) {}
}
