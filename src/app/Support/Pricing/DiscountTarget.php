<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class DiscountTarget
{
    public function __construct(
        public string $targetType,
        public int $targetId,
    ) {}
}
