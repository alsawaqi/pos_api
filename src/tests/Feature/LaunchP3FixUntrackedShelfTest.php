<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3SyncEvents;
use Tests\TestCase;

/**
 * LAUNCH-P3 fix order 1, K7 — only a shelf product (stock_mode unit or
 * cooked) moves its branch shelf count on sale and void. A product switched
 * to untracked or made-to-order keeps a leftover stock_qty, which never
 * moves (the pos_admin reversal copy follows the same rule).
 *
 * Water (untracked), Juice (made-to-order, no recipe), Cola (unit): each has
 * a leftover / real shelf count of 5 at branch 10.
 */
class LaunchP3FixUntrackedShelfTest extends TestCase
{
    use LaunchP3SyncEvents;
    use RefreshDatabase;

    private const WATER = 1;

    private const JUICE = 2;

    private const COLA = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            ['id' => self::WATER, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Water', 'base_price' => 0.200, 'status' => 'active', 'stock_mode' => 'untracked'] + $t,
            ['id' => self::JUICE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Juice', 'base_price' => 1.000, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
            ['id' => self::COLA, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cola', 'base_price' => 0.500, 'status' => 'active', 'stock_mode' => 'unit'] + $t,
        ]);
        foreach ([self::WATER, self::JUICE, self::COLA] as $id) {
            DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $id, 'is_available' => true, 'stock_qty' => 5] + $t);
        }
        Device::factory()->paired('mdev_k7')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function shelf(int $productId): float
    {
        return (float) DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', $productId)->value('stock_qty');
    }

    private function ledgerRows(int $productId): int
    {
        return DB::table('pos_product_stock_movements')->where('product_id', $productId)->count();
    }

    public function test_an_untracked_or_made_to_order_product_never_moves_a_leftover_shelf_count(): void
    {
        $uuid = (string) Str::uuid();
        $lines = [$this->line(self::WATER, 2, 200), $this->line(self::JUICE, 1, 1000), $this->line(self::COLA, 2, 500)];
        $this->pushProcessed('mdev_k7', [
            $this->orderEvent('order.create', $uuid, now(), $lines),
            $this->payEvent($uuid, now(), 2400),
        ]);

        $this->assertSame(5.0, $this->shelf(self::WATER));
        $this->assertSame(5.0, $this->shelf(self::JUICE));
        $this->assertSame(0, $this->ledgerRows(self::WATER));
        $this->assertSame(0, $this->ledgerRows(self::JUICE));
        // A unit product still sells off its shelf.
        $this->assertSame(3.0, $this->shelf(self::COLA));

        $this->pushProcessed('mdev_k7', [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $uuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'test'],
        ]]);

        $this->assertSame(5.0, $this->shelf(self::WATER));
        $this->assertSame(5.0, $this->shelf(self::JUICE));
        $this->assertSame(0, $this->ledgerRows(self::WATER));
        $this->assertSame(5.0, $this->shelf(self::COLA));
    }
}
