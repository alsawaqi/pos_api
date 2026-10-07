<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Product;

final readonly class QrResolvedLine
{
    /**
     * @param  list<QrResolvedAddOn>  $addons  the line product's own add-ons
     *                                         (a meal: the MAIN's add-ons)
     * @param  list<QrResolvedComponent>  $components  LAUNCH combo add-on: the
     *                                                 items of a combo or meal
     *                                                 line, per ONE combo /
     *                                                 meal (a meal's main is
     *                                                 the line product)
     * @param  QrResolvedMeal|null  $meal  LAUNCH combo add-on: the line is a
     *                                     meal on the main $product
     */
    public function __construct(
        public Product $product,
        public int $qty,
        public string $notes,
        public int $basePriceBaisas,
        public int $unitPriceBaisas,
        public array $addons,
        public array $components = [],
        public ?QrResolvedMeal $meal = null,
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
        return $this->meal === null && $this->product->isCombo();
    }

    public function isMeal(): bool
    {
        return $this->meal !== null;
    }

    /** A combo or a meal: one parent line with child lines. */
    public function hasChildren(): bool
    {
        return $this->isCombo() || $this->isMeal();
    }

    /** What the receipt and kitchen call the line ("Beef burger meal" for a meal). */
    public function displayName(): string
    {
        return $this->meal === null ? (string) $this->product->name : trim($this->product->name.' '.$this->meal->name);
    }

    public function displayNameAr(): ?string
    {
        if ($this->meal === null) {
            return $this->product->name_ar;
        }
        $main = $this->product->name_ar ?? $this->product->name;
        $meal = $this->meal->nameAr ?? $this->meal->name;

        return trim($main.' '.$meal);
    }
}
