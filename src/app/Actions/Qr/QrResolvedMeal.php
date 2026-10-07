<?php

declare(strict_types=1);

namespace App\Actions\Qr;

/**
 * LAUNCH combo add-on — the meal a line was made into ("Make it a meal?"):
 * the line's product is the main; the meal price is added to its own price.
 */
final readonly class QrResolvedMeal
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $nameAr,
        public int $mealPriceBaisas,
    ) {}
}
