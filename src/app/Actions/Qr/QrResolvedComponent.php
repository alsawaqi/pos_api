<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;

/**
 * LAUNCH-P4 — one item chosen inside a combo line, per ONE combo: the slot
 * it was chosen in, the product, how many, the option's extra price and the
 * item's own add-ons (at their own prices).
 */
final readonly class QrResolvedComponent
{
    /** @param list<QrResolvedAddOn> $addons */
    public function __construct(
        public int $slotId,
        public string $slotName,
        public ?string $slotNameAr,
        public Product $product,
        public int $qty,
        public int $extraPriceBaisas,
        public string $notes,
        public array $addons,
    ) {}

    /** What this choice adds to ONE combo: qty × (extra price + its add-on prices). */
    public function priceBaisas(): int
    {
        return $this->qty * ($this->extraPriceBaisas + array_sum(array_map(
            static fn (QrResolvedAddOn $addon): int => $addon->priceDeltaBaisas,
            $this->addons,
        )));
    }

    /** @return list<int> */
    public function addonIds(): array
    {
        return array_map(static fn (QrResolvedAddOn $resolved): int => (int) $resolved->addon->id, $this->addons);
    }
}
