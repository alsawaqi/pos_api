<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class LineDiscountResult
{
    public function __construct(
        public int $lineIndex,
        public int $amountBaisas,
        public ?int $ruleId = null,
        public ?string $amountType = null,
        public string $label = '',
    ) {}
}
