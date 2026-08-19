<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final readonly class OfferSpec
{
    /**
     * @param  array<string, mixed>  $config
     * @param  list<int>  $branchScope
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public ?string $nameAr = null,
        public array $config = [],
        public bool $autoApply = true,
        public ?DateTimeImmutable $validityStart = null,
        public ?DateTimeImmutable $validityEnd = null,
        public ?int $dayOfWeekMask = null,
        public ?string $timeStart = null,
        public ?string $timeEnd = null,
        public array $branchScope = [],
        public ?int $maxPerOrder = null,
        public bool $isActive = true,
    ) {}

    public function isBundle(): bool
    {
        return $this->type === 'bundle';
    }
}
