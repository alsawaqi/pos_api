<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class CompWireRow
{
    public function __construct(
        public int $amountBaisas,
        public bool $isGift = false,
        public ?int $lineIndex = null,
        public ?int $reasonId = null,
    ) {}
}
