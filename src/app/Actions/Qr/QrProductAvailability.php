<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\BranchProduct;
use App\Models\Product;
use DateTimeInterface;

final readonly class QrProductAvailability
{
    public const BRANCH_UNAVAILABLE = 'branch_unavailable';

    public const INACTIVE = 'inactive';

    public const OUTSIDE_AVAILABILITY_WINDOW = 'outside_availability_window';

    public const OUT_OF_STOCK = 'out_of_stock';

    private function __construct(public bool $available, public ?string $reason) {}

    public static function evaluate(Product $product, ?BranchProduct $branchProduct, DateTimeInterface $at): self
    {
        if ($branchProduct !== null && ! $branchProduct->is_available) {
            return new self(false, self::BRANCH_UNAVAILABLE);
        }
        if ((string) $product->status !== 'active') {
            return new self(false, self::INACTIVE);
        }
        if (! self::isInsideWindow($at->format('H:i:s'), $product->available_from, $product->available_until)) {
            return new self(false, self::OUTSIDE_AVAILABILITY_WINDOW);
        }
        if (in_array((string) $product->stock_mode, ['unit', 'cooked'], true)
            && $branchProduct?->stock_qty !== null
            && (float) $branchProduct->stock_qty <= 0.0) {
            return new self(false, self::OUT_OF_STOCK);
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
