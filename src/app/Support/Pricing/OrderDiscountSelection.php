<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class OrderDiscountSelection
{
    private function __construct(
        public OrderDiscountKind $kind,
        public int $fixedBaisas,
        public float $percent,
        public string $label,
        public ?int $discountId,
        public string $reason,
        public ?int $loyaltyRuleId,
        public int $loyaltyPoints,
        public int $loyaltyStamps,
    ) {}

    public static function none(): self
    {
        return new self(OrderDiscountKind::None, 0, 0.0, '', null, '', null, 0, 0);
    }

    public static function fixed(
        int $fixedBaisas,
        string $label = '',
        ?int $discountId = null,
        string $reason = '',
        ?int $loyaltyRuleId = null,
        int $loyaltyPoints = 0,
        int $loyaltyStamps = 0,
    ): self {
        return new self(
            OrderDiscountKind::FixedAmount,
            $fixedBaisas,
            0.0,
            $label,
            $discountId,
            $reason,
            $loyaltyRuleId,
            $loyaltyPoints,
            $loyaltyStamps,
        );
    }

    public static function percentage(
        float $percent,
        string $label = '',
        ?int $discountId = null,
        string $reason = '',
    ): self {
        return new self(
            OrderDiscountKind::Percentage,
            0,
            $percent,
            $label,
            $discountId,
            $reason,
            null,
            0,
            0,
        );
    }

    public function isActive(): bool
    {
        return $this->kind !== OrderDiscountKind::None
            && ($this->kind === OrderDiscountKind::Percentage ? $this->percent > 0 : $this->fixedBaisas > 0);
    }

    public function isLoyaltyRedemption(): bool
    {
        return $this->loyaltyRuleId !== null;
    }
}
