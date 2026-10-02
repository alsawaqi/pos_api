<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\QrProductAvailability;
use App\Models\BranchProduct;
use App\Models\Product;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * LAUNCH-P2 P2-7 — sell, but warn on the QR path: a unit or cooked product
 * whose branch shelf count is at or below zero stays on the customer menu and
 * can be ordered (QR quick orders, dine-in rounds, product add-ons), as on the
 * till and handheld. The merchant's explicit "not available" still holds.
 */
class LaunchP2QrSellButWarnTest extends TestCase
{
    private function product(array $attributes = []): Product
    {
        return (new Product)->forceFill(array_merge([
            'status' => 'active', 'stock_mode' => 'unit', 'available_from' => null, 'available_until' => null,
        ], $attributes));
    }

    private function shelf(?string $qty, bool $available = true): BranchProduct
    {
        return (new BranchProduct)->forceFill(['is_available' => $available, 'stock_qty' => $qty]);
    }

    public function test_stock_numbers_never_take_a_product_off_the_qr_menu(): void
    {
        $at = new DateTimeImmutable('2026-10-02 12:00:00');

        foreach (['unit', 'cooked'] as $mode) {
            foreach (['0.000', '-3.000'] as $qty) {
                $availability = QrProductAvailability::evaluate($this->product(['stock_mode' => $mode]), $this->shelf($qty), $at);
                $this->assertTrue($availability->available, "{$mode} at {$qty}");
                $this->assertNull($availability->reason);
            }
        }
    }

    public function test_explicit_unavailability_still_holds(): void
    {
        $at = new DateTimeImmutable('2026-10-02 12:00:00');

        $this->assertSame(QrProductAvailability::BRANCH_UNAVAILABLE, QrProductAvailability::evaluate($this->product(), $this->shelf('5.000', false), $at)->reason);
        $this->assertSame(QrProductAvailability::INACTIVE, QrProductAvailability::evaluate($this->product(['status' => 'inactive']), $this->shelf('0.000'), $at)->reason);
        $this->assertSame(
            QrProductAvailability::OUTSIDE_AVAILABILITY_WINDOW,
            QrProductAvailability::evaluate($this->product(['available_from' => '06:00:00', 'available_until' => '10:00:00']), $this->shelf('0.000'), $at)->reason,
        );
    }
}
