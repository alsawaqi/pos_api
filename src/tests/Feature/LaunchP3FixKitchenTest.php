<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P3 fix order 1, L6 — the kitchen screen works from EXACT per-piece
 * amounts, and a batch writes no 0-quantity rows.
 *
 * Saffron syrup (prep, makes 500 ml): saffron 0.001 kg + sugar 0.25 kg.
 * Spice mix (prep, makes 500 ml): cardamom 0.125 kg.
 * Saffron cake (cooked): 15 ml syrup → per piece saffron 0.00003 kg, sugar 0.0075 kg.
 * Kahwa cake (cooked): 15 ml spice mix → per piece cardamom 0.00375 kg.
 * Branch: saffron 0.002 kg (66 cakes), sugar 9.25 kg (1233 cakes), cardamom 0.375 kg (100 cakes).
 */
class LaunchP3FixKitchenTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use RefreshDatabase;

    private const SAFFRON = 11;

    private const SUGAR = 12;

    private const SYRUP = 13;

    private const CARDAMOM = 14;

    private const SPICE = 15;

    private const CAKE = 3;

    private const KAHWA_CAKE = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(self::SAFFRON, 'Saffron', 'kg', '2000.000000'),
            $this->ingredientRow(self::SUGAR, 'Sugar', 'kg', '0.500000'),
            $this->ingredientRow(self::SYRUP, 'Saffron syrup', 'ml', '0', isPrep: true, yield: '500'),
            $this->ingredientRow(self::CARDAMOM, 'Cardamom', 'kg', '8.000000'),
            $this->ingredientRow(self::SPICE, 'Spice mix', 'ml', '0', isPrep: true, yield: '500'),
        ]);
        $this->prepRecipe(self::SYRUP, [[self::SAFFRON, '0.001'], [self::SUGAR, '0.25']]);
        $this->prepRecipe(self::SPICE, [[self::CARDAMOM, '0.125']]);
        DB::table('pos_products')->insert([
            ['id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Saffron cake', 'base_price' => 1.0, 'status' => 'active', 'stock_mode' => 'cooked'] + $t,
            ['id' => self::KAHWA_CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Kahwa cake', 'base_price' => 1.0, 'status' => 'active', 'stock_mode' => 'cooked'] + $t,
        ]);
        $this->productRecipe(self::CAKE, [[self::SYRUP, '15', 'ml']]);
        $this->productRecipe(self::KAHWA_CAKE, [[self::SPICE, '15', 'ml']]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::SAFFRON, 'quantity' => '0.002'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::SUGAR, 'quantity' => '9.25'] + $t,
            ['branch_id' => 10, 'ingredient_id' => self::CARDAMOM, 'quantity' => '0.375'] + $t,
        ]);
        Device::factory()->paired('mdev_l6')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kitchenProduct(int $id): array
    {
        $products = $this->withToken('mdev_l6')->getJson('/api/v1/device/kitchen')->assertOk()->json('data.products');

        return collect($products)->firstWhere('id', $id);
    }

    /** The till's start-dialog check (pos_machine): line.quantity × N must fit the balance. */
    private function tillCovered(array $product, int $pieces): bool
    {
        foreach ($product['recipe'] as $line) {
            if ((float) $line['quantity'] * $pieces > (float) $line['branch_balance'] + 1e-9) {
                return false;
            }
        }

        return true;
    }

    public function test_the_saffron_cake_can_make_66_from_the_exact_per_piece_saffron(): void
    {
        $cake = $this->kitchenProduct(self::CAKE);

        $this->assertSame(0.00003, collect($cake['recipe'])->firstWhere('ingredient_id', self::SAFFRON)['quantity']);
        $this->assertSame(0.0075, collect($cake['recipe'])->firstWhere('ingredient_id', self::SUGAR)['quantity']);
        $this->assertSame(66, $cake['max_producible']);
        // 100 pieces need 0.003 kg of saffron: the till now sees the shortfall.
        $this->assertFalse($this->tillCovered($cake, 100));
        $this->assertTrue($this->tillCovered($cake, 66));
    }

    public function test_an_amount_that_rounded_up_no_longer_understates_can_make(): void
    {
        $cake = $this->kitchenProduct(self::KAHWA_CAKE);

        $this->assertSame(0.00375, $cake['recipe'][0]['quantity']);
        $this->assertSame(100, $cake['max_producible']);
        $this->assertTrue($this->tillCovered($cake, 100));

        // The batch deducts exactly what the screen promised.
        $data = $this->withToken('mdev_l6')->postJson('/api/v1/device/productions', [
            'product_id' => self::KAHWA_CAKE, 'quantity' => 100, 'staff_id' => 7, 'extras' => [],
        ])->assertCreated()->json('data');
        $this->assertSame([], $data['ingredient_shortfalls']);
        $this->assertEqualsWithDelta(0.0, $this->branchBalance(self::CARDAMOM), 1e-9);
    }

    public function test_a_one_piece_batch_writes_no_zero_quantity_rows(): void
    {
        $this->withToken('mdev_l6')->postJson('/api/v1/device/productions', [
            'product_id' => self::CAKE, 'quantity' => 1, 'staff_id' => 7, 'extras' => [],
        ])->assertCreated();

        // 0.00003 kg of saffron rounds to nothing at the ledger's 4 decimals.
        $this->assertSame(0, DB::table('pos_production_lines')->where('ingredient_id', self::SAFFRON)->count());
        $this->assertSame(0, DB::table('pos_stock_movements')->where('ingredient_id', self::SAFFRON)->count());
        $this->assertSame(0, DB::table('pos_production_lines')->where('quantity', 0)->count());
        $this->assertSame(0, DB::table('pos_stock_movements')->where('quantity', 0)->count());
        // The sugar line is written as before.
        $this->assertEqualsWithDelta(-0.0075, (float) DB::table('pos_stock_movements')->where('ingredient_id', self::SUGAR)->value('quantity'), 1e-9);
    }
}
