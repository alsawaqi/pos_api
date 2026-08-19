<?php

declare(strict_types=1);

namespace App\Support\Pricing;

enum OrderDiscountKind
{
    case None;
    case FixedAmount;
    case Percentage;
}
