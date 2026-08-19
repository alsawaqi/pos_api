<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final readonly class DiscountRule
{
    /**
     * @param  list<int>  $branchScope
     * @param  list<DiscountTarget>  $targets
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $scope,
        public string $amountType,
        public ?int $fixedBaisas = null,
        public ?float $percent = null,
        public ?DateTimeImmutable $validityStart = null,
        public ?DateTimeImmutable $validityEnd = null,
        public ?int $dayOfWeekMask = null,
        public ?string $timeStart = null,
        public ?string $timeEnd = null,
        public array $branchScope = [],
        public bool $stackable = false,
        public bool $requiresManagerApproval = false,
        public bool $isActive = true,
        public bool $autoApply = false,
        public array $targets = [],
    ) {}

    public function isOrderScope(): bool
    {
        return $this->scope === 'order';
    }

    public function appliesToProduct(?int $productId, ?int $categoryId): bool
    {
        if ($this->scope === 'product') {
            foreach ($this->targets as $target) {
                if ($productId !== null && $target->targetType === 'product' && $target->targetId === $productId) {
                    return true;
                }
            }
        }
        if ($this->scope === 'category') {
            foreach ($this->targets as $target) {
                if ($categoryId !== null && $target->targetType === 'category' && $target->targetId === $categoryId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function amountForOmr(float $subtotalOmr): float
    {
        $raw = $this->amountType === 'percent'
            ? $subtotalOmr * (($this->percent ?? 0.0) / 100)
            : Money::baisasToOmr($this->fixedBaisas ?? 0);

        return Money::roundOmr(min(max($raw, 0.0), $subtotalOmr));
    }
}
