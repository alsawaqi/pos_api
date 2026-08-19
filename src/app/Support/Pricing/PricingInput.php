<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final readonly class PricingInput
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  list<DiscountRule>  $discountRules
     * @param  list<OfferSpec>  $offers
     * @param  list<TaxSpec>  $taxes
     */
    public function __construct(
        public array $lines,
        public DateTimeImmutable $now,
        public array $discountRules = [],
        public array $offers = [],
        ?OrderDiscountSelection $orderDiscount = null,
        public ?CompSelection $comp = null,
        public array $taxes = [],
        public bool $isDeliveryProvider = false,
        public ?int $branchId = null,
    ) {
        $this->orderDiscount = $orderDiscount ?? OrderDiscountSelection::none();
    }

    public OrderDiscountSelection $orderDiscount;
}
