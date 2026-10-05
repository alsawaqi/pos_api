<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\BranchProduct;
use App\Models\Product;
use App\Support\BusinessClock;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Catalogue\SaleDates;
use DateTimeInterface;

/**
 * Whether a product can be ordered from the QR menu / a QR or table round
 * right now: the merchant's explicit availability only — switched off at the
 * branch, inactive, or outside its daily window.
 *
 * LAUNCH-P2 P2-7 — sell, but warn: stock numbers never block a sale. A unit
 * or cooked product whose branch shelf count is at or below zero stays on
 * the menu and orderable (the books go negative and the merchant is warned),
 * exactly like the till and handheld. OUT_OF_STOCK is no longer produced; the
 * constant stays because rounds held before this change carry it.
 */
final readonly class QrProductAvailability
{
    public const BRANCH_UNAVAILABLE = 'branch_unavailable';

    public const INACTIVE = 'inactive';

    public const OUTSIDE_AVAILABILITY_WINDOW = 'outside_availability_window';

    /** No longer produced since LAUNCH-P2 (see the class comment). */
    public const OUT_OF_STOCK = 'out_of_stock';

    /** LAUNCH-P4 — the branch switched it off by hand ("sold out"), on every channel. */
    public const SOLD_OUT = 'sold_out';

    /**
     * LAUNCH review add-on (tester call 13) — outside the product's
     * limited-time dates (on_sale_from / on_sale_until, Asia/Muscat). The QR
     * menu leaves such a product out; the pricer refuses it like the daily
     * hours; a staff round holds it with this reason.
     */
    public const OUTSIDE_DATES = 'outside_dates';

    private function __construct(public bool $available, public ?string $reason) {}

    /**
     * LAUNCH-P4 — the branch rule follows the product's branch scope
     * ({@see BranchCatalogue}); a hand-set sold-out switch (never stock
     * numbers) makes it unorderable after the merchant's own switches.
     */
    public static function evaluate(Product $product, ?BranchProduct $branchProduct, DateTimeInterface $at, bool $soldOut = false): self
    {
        if (! BranchCatalogue::availableAt($product, $branchProduct)) {
            return new self(false, self::BRANCH_UNAVAILABLE);
        }
        if ((string) $product->status !== 'active') {
            return new self(false, self::INACTIVE);
        }
        if (! SaleDates::isOnSale($product, $at)) {
            return new self(false, self::OUTSIDE_DATES);
        }
        if ($soldOut) {
            return new self(false, self::SOLD_OUT);
        }
        // LAUNCH-P4 H9 — the daily window is the merchant's wall clock
        // (pos.business_timezone, Asia/Muscat), never UTC.
        if (! self::isInsideWindow(BusinessClock::local($at)->format('H:i:s'), $product->available_from, $product->available_until)) {
            return new self(false, self::OUTSIDE_AVAILABILITY_WINDOW);
        }

        return new self(true, null);
    }

    private static function isInsideWindow(string $now, ?string $start, ?string $end): bool
    {
        if ($start === null && $end === null) {
            return true;
        }
        if ($start === null) {
            return $now <= $end;
        }
        if ($end === null) {
            return $now >= $start;
        }
        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }
}
