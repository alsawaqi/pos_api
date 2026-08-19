<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class CompSelection
{
    public function __construct(
        public ?int $lineIndex = null,
        public ?int $qty = null,
        public ?int $reasonId = null,
        public string $reason = '',
    ) {}
}
