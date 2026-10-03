<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OrderItem;
use App\Support\Recipes\PrepExploder;
use App\Support\Recipes\RecipeCopy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP3SyncEvents;
use Tests\TestCase;

/**
 * LAUNCH-P3 fix order 1, M1-b — an order line's recipe copy keeps its
 * exploded per-unit quantities at 8 decimals, so the cost of goods read from
 * the copy equals the theoretical cost.
 *
 * Saffron syrup (prep, makes 1000 ml): saffron 0.002 kg @ 2000, sugar 0.5 kg @ 0.4.
 * Saffron latte: 15 ml syrup → saffron 0.00003 kg (0.060) + sugar 0.0075 kg (0.003) = 0.063.
 * Kahwa base (prep, makes 1000 ml): saffron 0.001 kg. Kahwa cup: 50 ml → saffron 0.00005 kg = 0.100.
 */
class LaunchP3FixCopyPrecisionTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP3SyncEvents;
    use RefreshDatabase;

    private const SAFFRON = 21;

    private const SUGAR = 22;

    private const SYRUP = 23;

    private const KAHWA_BASE = 24;

    private const LATTE = 31;

    private const KAHWA = 32;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        Device::factory()->paired('mdev_m1b')->create(['company_id' => 100, 'branch_id' => 10]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(self::SAFFRON, 'Saffron', 'kg', '2000.000000'),
            $this->ingredientRow(self::SUGAR, 'Sugar', 'kg', '0.400000'),
            $this->ingredientRow(self::SYRUP, 'Saffron syrup', 'ml', '0', isPrep: true, yield: '1000'),
            $this->ingredientRow(self::KAHWA_BASE, 'Kahwa base', 'ml', '0', isPrep: true, yield: '1000'),
        ]);
        $this->prepRecipe(self::SYRUP, [[self::SAFFRON, '0.002'], [self::SUGAR, '0.5']]);
        $this->prepRecipe(self::KAHWA_BASE, [[self::SAFFRON, '0.001']]);
        DB::table('pos_products')->insert([
            ['id' => self::LATTE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Saffron latte', 'base_price' => 1.800, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
            ['id' => self::KAHWA, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Kahwa', 'base_price' => 0.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t,
        ]);
        $this->productRecipe(self::LATTE, [[self::SYRUP, '15', 'ml']]);
        $this->productRecipe(self::KAHWA, [[self::KAHWA_BASE, '50', 'ml']]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::SAFFRON, 'quantity' => '1'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::SUGAR, 'quantity' => '10'] + $t,
        ]);
    }

    /** Σ qty × unit_cost of a stored copy, per one unit, 6 decimals. */
    private function copyCost(OrderItem $item): string
    {
        $total = BigDecimal::zero();
        foreach ((array) $item->recipe_snapshot_json as $line) {
            $total = $total->plus(BigDecimal::of(sprintf('%.10F', $line['qty']))->multipliedBy(BigDecimal::of(sprintf('%.6F', $line['unit_cost']))));
        }

        return (string) $total->toScale(6, RoundingMode::HALF_UP);
    }

    public function test_the_copy_per_latte_holds_the_saffron_and_its_cost_of_goods_is_the_theoretical_cost(): void
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(5)->startOfSecond();
        $this->pushProcessed('mdev_m1b', [
            $this->orderEvent('order.create', $uuid, $at, [$this->line(self::LATTE, 100, 1800)]),
            $this->payEvent($uuid, $at, 180000),
        ]);

        $item = OrderItem::query()->sole();
        $this->assertSame([0.00003], $this->copiedQty($uuid, self::SAFFRON));
        $this->assertSame([0.0075], $this->copiedQty($uuid, self::SUGAR));
        $this->assertSame('0.063000', $this->copyCost($item));
        $this->assertSame('0.063000', (new PrepExploder(100))->cost([['ingredient_id' => self::SYRUP, 'quantity' => '15']]));

        // ConsumeInventoryAction keeps its P2 rule (the per-unit plan rounds to
        // the ledger's 4 decimals before × qty): sugar moves, saffron does not.
        // The portal's save-time guard (fix order 1 M1-a) now refuses such a
        // recipe, so the stock error stays within 1 %.
        $this->assertSame(-0.75, (float) DB::table('pos_stock_movements')->where('ingredient_id', self::SUGAR)->sum('quantity'));
        $this->assertSame(0, DB::table('pos_stock_movements')->where('ingredient_id', self::SAFFRON)->count());
    }

    public function test_a_half_unit_amount_is_not_doubled_in_the_copy(): void
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(5)->startOfSecond();
        $this->pushProcessed('mdev_m1b', [$this->orderEvent('order.create', $uuid, $at, [$this->line(self::KAHWA, 1, 500)])]);

        // Exact 0.00005 kg a cup; at 4 decimals the copy held 0.0001 (cost 0.200).
        $this->assertSame([0.00005], $this->copiedQty($uuid, self::SAFFRON));
        $this->assertSame('0.100000', $this->copyCost(OrderItem::query()->sole()));
    }

    public function test_add_on_option_lines_keep_eight_decimals_too(): void
    {
        $lines = (new RecipeCopy(100))->consumptionLines([(object) [
            'ingredient_id' => self::SYRUP, 'component_product_id' => null, 'direction' => 'add', 'quantity' => '15', 'unit' => 'ml',
        ]]);

        $this->assertSame([0.00003, 0.0075], array_column($lines, 'qty'));
    }
}
