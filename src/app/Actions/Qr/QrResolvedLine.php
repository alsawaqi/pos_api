<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;

final readonly class QrResolvedLine
{
    /** @param list<QrResolvedAddOn> $addons */
    public function __construct(
        public Product $product,
        public int $qty,
        public string $notes,
        public int $basePriceBaisas,
        public int $unitPriceBaisas,
        public array $addons,
    ) {}

    /** @return list<int> */
    public function addonIds(): array
    {
        return array_map(
            static fn (QrResolvedAddOn $resolved): int => (int) $resolved->addon->id,
            $this->addons,
        );
    }
}
