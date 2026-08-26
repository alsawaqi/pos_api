<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Support\Pricing\DiscountRule;
use App\Support\Pricing\PricingInput;

final readonly class QrPricingLoadResult
{
    /** @param list<QrResolvedLine> $resolvedLines */
    public function __construct(
        public PricingInput $pricingInput,
        public array $resolvedLines,
        public ?DiscountRule $autoOrderDiscount,
    ) {}
}
