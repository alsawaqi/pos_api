<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;

/**
 * LAUNCH combo add-on — one item inside a combo or meal line, per ONE combo /
 * meal: the line it came from (line id, its question name for a choice), its
 * kind ('fixed' | 'upgrade' | 'choice'), the product actually served, how
 * many, its price inside the combo (a choice extra or an upgrade price, per
 * item; 0 for a plain fixed item) and the item's own add-ons (at their own
 * prices, a Remove option may be below 0).
 */
final readonly class QrResolvedComponent
{
    /** @param list<QrResolvedAddOn> $addons */
    public function __construct(
        public int $lineId,
        public string $kind,
        public ?string $lineName,
        public ?string $lineNameAr,
        public Product $product,
        public int $qty,
        public int $extraPriceBaisas,
        public string $notes,
        public array $addons,
        // true = a fixed line the client left out, served as is (not part of
        // the request, so an idempotent replay compares without it).
        public bool $filled = false,
    ) {}

    /** What this item adds to ONE combo / meal: qty × (its extra or upgrade price + its add-on prices). */
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
