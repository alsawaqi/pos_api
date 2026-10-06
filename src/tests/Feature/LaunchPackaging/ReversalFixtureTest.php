<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchPackaging;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LAUNCH packaging add-on — the void side of stock by order type, on the
 * fixture shared with pos_admin (tests/Fixtures/launch-packaging-reversal.json,
 * byte-identical in both repos). pos_admin's reversal copy runs the same
 * cases (tests/Feature/LaunchPackaging/ReversalCopyTest.php) and must
 * restore the same amounts: a stamped to-go order restores only its to-go
 * lines and its frozen packaging; an order stocked before the release (no
 * stamp, legacy live components now ticked "not dine in") restores every
 * line.
 */
final class ReversalFixtureTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): iterable
    {
        foreach (array_keys(self::fixture()['cases']) as $name) {
            yield $name => [$name];
        }
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/launch-packaging-reversal.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('cases')]
    public function test_a_void_restores_exactly_the_fixtures_amounts(string $name): void
    {
        $fixture = self::fixture();
        [$order, $ingredients, $products] = $this->load($fixture, $name);

        DB::transaction(fn () => app(ConsumeInventoryAction::class)->reverse($order));

        $restored = ['ingredients' => [], 'products' => []];
        foreach ($ingredients as $key => $id) {
            $restored['ingredients'][$key] = round((float) DB::table('pos_branch_stock')->where('ingredient_id', $id)->value('quantity') - 1000, 4);
        }
        foreach (array_intersect_key($products, $fixture['cases'][$name]['expected_restore']['products']) as $key => $id) {
            $restored['products'][$key] = round((float) DB::table('pos_branch_product')->where('product_id', $id)->value('stock_qty') - 100, 3);
        }
        $this->assertEquals($fixture['cases'][$name]['expected_restore'], $restored);

        // pos_api's own consume of the same stamped order takes exactly that back.
        DB::transaction(fn () => app(ConsumeInventoryAction::class)->consume(Order::query()->findOrFail($order->id)));
        $this->assertSame(0, DB::table('pos_branch_stock')->where('quantity', '<>', 1000)->count());
        $this->assertSame(0, DB::table('pos_branch_product')->where('stock_qty', '<>', 100)->count());
    }

    /** @return array{0: Order, 1: array<string, int>, 2: array<string, int>} */
    private function load(array $fixture, string $name): array
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        $ingredients = [];
        foreach ($fixture['ingredients'] as $key => $row) {
            $ingredients[$key] = (int) DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => $key, 'unit' => $row['unit'], 'default_unit_cost' => $row['cost'], 'status' => 'active'] + $t);
            DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => $ingredients[$key], 'quantity' => '1000'] + $t);
        }
        $products = [];
        foreach ($fixture['products'] as $key => $row) {
            $products[$key] = (int) DB::table('pos_products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => $key,
                'base_price' => '1.000', 'stock_mode' => $row['stock_mode'], 'status' => 'active'] + $t);
            if ($row['stock_mode'] === 'unit') {
                DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $products[$key], 'is_available' => true, 'stock_qty' => '100.000'] + $t);
            }
        }
        foreach ($fixture['live_components'] as $parent => $lines) {
            foreach ($lines as $line) {
                DB::table('pos_product_components')->insert(['product_id' => $products[$parent], 'component_product_id' => $products[$line['product']],
                    'quantity' => $line['qty'], 'order_types' => $line['order_types'] ?? 15] + $t);
            }
        }
        $resolve = static function (?array $lines) use ($ingredients, $products): ?array {
            if ($lines === null) {
                return null;
            }

            return array_map(static function (array $line) use ($ingredients, $products): array {
                if (isset($line['ingredient'])) {
                    $line = ['ingredient_id' => $ingredients[$line['ingredient']]] + array_diff_key($line, ['ingredient' => 1]);
                }
                if (isset($line['product'])) {
                    $line = ['product_id' => $products[$line['product']]] + array_diff_key($line, ['product' => 1]);
                }

                return $line;
            }, $lines);
        };

        $case = $fixture['cases'][$name];
        $packaging = $case['order']['packaging'];
        $orderId = (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'order_type' => $case['order']['order_type'], 'source' => 'main_pos', 'status' => 'paid', 'subtotal' => '3.000',
            'grand_total' => '3.000', 'opened_at' => '2026-10-06 08:00:00', 'closed_at' => '2026-10-06 08:05:00',
            'stock_order_type' => $case['order']['stock_order_type'],
            'packaging_snapshot_json' => $packaging === null ? null
                : json_encode(['order_type' => $packaging['order_type'], 'lines' => $resolve($packaging['lines'])]),
        ] + $t);
        foreach ($case['items'] as $item) {
            $itemId = (int) DB::table('pos_order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $products[$item['product']],
                'product_name_snapshot' => $item['product'], 'qty' => $item['qty'], 'unit_price_snapshot' => '1.500', 'line_total' => '3.000',
                'recipe_snapshot_json' => json_encode($resolve($item['recipe'])),
                'component_snapshot_json' => $item['components'] === null ? null : json_encode($resolve($item['components'])),
            ] + $t);
            foreach ($item['addons'] as $addon) {
                DB::table('pos_order_item_addons')->insert(['order_item_id' => $itemId, 'add_on_name_snapshot' => 'option',
                    'price_delta_snapshot' => '0.000',
                    'consumption_snapshot_json' => isset($addon['consumption']) ? json_encode($resolve($addon['consumption'])) : null,
                    'ingredient_snapshot_json' => isset($addon['trio']) ? json_encode($resolve([$addon['trio']])[0]) : null,
                ] + $t);
            }
        }

        return [Order::query()->findOrFail($orderId), $ingredients, $products];
    }
}
