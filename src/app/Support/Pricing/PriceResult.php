<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final readonly class PriceResult
{
    /**
     * @param  list<LineDiscountResult>  $lineDiscounts
     * @param  list<AppliedOfferResult>  $appliedOffers
     * @param  array<int, int>  $giftAmountsBaisas
     * @param  list<TaxLineResult>  $taxLines
     */
    public function __construct(
        public int $rawSubtotalBaisas,
        public array $lineDiscounts,
        public int $lineDiscountTotalBaisas,
        public array $appliedOffers,
        public int $offerDiscountTotalBaisas,
        public int $orderDiscountBaisas,
        public int $discountTotalBaisas,
        public int $subtotalBaisas,
        public array $giftAmountsBaisas,
        public int $giftedTotalBaisas,
        public int $managerCompBaisas,
        public int $compTotalBaisas,
        public int $taxedBaseBaisas,
        public array $taxLines,
        public int $taxTotalBaisas,
        public int $grandTotalBaisas,
        // LAUNCH-P4 — true: grand already contains the tax (grand = taxed base).
        public bool $pricesIncludeTax = false,
    ) {}

    public function orderDiscountRowBaisas(): int
    {
        return max(0, $this->discountTotalBaisas - $this->lineDiscountTotalBaisas - $this->offerDiscountTotalBaisas);
    }
}
