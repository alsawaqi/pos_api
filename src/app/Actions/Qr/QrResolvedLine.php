<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;

final readonly class QrResolvedLine
{
    /**
     * @param  list<QrResolvedAddOn>  $addons
     * @param  list<QrResolvedComponent>  $components  LAUNCH-P4: the items
     *                                                 chosen inside a combo
     *                                                 line, per ONE combo
     */
    public function __construct(
        public Product $product,
        public int $qty,
        public string $notes,
        public int $basePriceBaisas,
        public int $unitPriceBaisas,
        public array $addons,
        public array $components = [],
    ) {}

    /** @return list<int> */
    public function addonIds(): array
    {
        return array_map(
            static fn (QrResolvedAddOn $resolved): int => (int) $resolved->addon->id,
            $this->addons,
        );
    }

    public function isCombo(): bool
    {
        return $this->product->isCombo();
    }
}
