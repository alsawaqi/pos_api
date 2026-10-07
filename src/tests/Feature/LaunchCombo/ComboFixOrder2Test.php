<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchCombo;

use App\Support\Pricing\ComboAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH combo add-on, Part A fix order 2 (LAUNCH-COMBO_A_FIX_ORDER_2.md):
 *
 *   C-16 the device config lists a main under the meal mealFor picks (the
 *        same active, on-sale set the pricer and the pricing check use)
 *   C-18 the split never builds one entry per unit (a huge quantity costs no
 *        memory, same shares), and a device line over 9 999 is flagged,
 *        never refused
 */
final class ComboFixOrder2Test extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burgers;

    private int $drinks;

    private int $beef;

    private int $chicken;

    private int $fries;

    private int $cola;

    private int $box;

    private int $boxBeef;

    private int $boxFries;

    private int $boxDrink;

    private int $meal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $this->burgers = $this->p4Category('Burgers');
        $this->drinks = $this->p4Category('Drinks');
        $this->beef = $this->p4Product('Beef burger', '2.000', ['category_id' => $this->burgers]);
        $this->chicken = $this->p4Product('Chicken burger', '1.800', ['category_id' => $this->burgers]);
        $this->fries = $this->p4Product('Fries', '1.000');
        $this->cola = $this->p4Product('Cola', '1.000', ['category_id' => $this->drinks]);
        $this->box = $this->p4Product('Family box', '5.000', ['product_type' => 'combo']);
        $this->boxBeef = $this->p4FixedLine(['combo_product_id' => $this->box], $this->beef, 2, [], 0);
        $this->boxFries = $this->p4FixedLine(['combo_product_id' => $this->box], $this->fries, 1, [], 1);
        $this->boxDrink = $this->p4ChoiceLine(['combo_product_id' => $this->box], $this->drinks, 1, [], [], 2);
        $this->meal = $this->p4Meal('meal', '1.200', [$this->burgers], [], ['sort_order' => 1]);
        $this->p4FixedLine(['meal_id' => $this->meal], $this->fries, 1, [], 0);
    }

    /** @return array<int, list<int>> meal id => mains, from the device config */
    private function configMains(): array
    {
        return collect($this->withToken('mdev_fix2_c16')->getJson('/api/v1/device/config')->assertOk()->json('data.meals'))
            ->mapWithKeys(static fn (array $meal): array => [$meal['id'] => $meal['mains']])->all();
    }

    public function test_c16_the_device_config_lists_a_main_under_its_own_meal_only(): void
    {
        $this->p4Device('mdev_fix2_c16');
        // A clash in the data: a second active meal on Burgers (lower priority), and a third that has ended.
        $big = $this->p4Meal('Big meal', '2.000', [$this->burgers], [$this->chicken], ['sort_order' => 2]);
        $this->p4FixedLine(['meal_id' => $big], $this->fries, 1, [], 0);
        $old = $this->p4Meal('Old meal', '0.500', [$this->burgers], [], ['sort_order' => 0, 'on_sale_until' => '2026-10-06']);
        $this->p4FixedLine(['meal_id' => $old], $this->fries, 1, [], 0);

        // The first meal by sort order, then id, wins every main it takes; the ended one is not sent.
        $this->assertSame([$this->meal => [$this->beef, $this->chicken], $big => []], $this->configMains());

        // Unticked in the first meal, Beef goes to the big meal; Chicken (unticked there) stays.
        DB::table('pos_meal_excluded_products')->insert(['company_id' => 100, 'meal_id' => $this->meal, 'product_id' => $this->beef,
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$this->meal => [$this->chicken], $big => [$this->beef]], $this->configMains());

        // The device config agrees with the pricer: the big meal is Beef's meal now.
        $quote = $this->p4QrPost($this->p4QrSession(), '/api/v1/public/qr/quote', ['lines' => [
            ['product_id' => $this->beef, 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'meal_id' => $big, 'combo' => []],
        ]]);
        $quote->assertOk()->assertJsonPath('data.quote.lines.0.unit_price_baisas', 4000);
    }

    public function test_c18_a_huge_quantity_is_split_without_one_entry_per_unit(): void
    {
        $children = [['weight' => 2000, 'qty' => 2], ['weight' => 1000, 'qty' => 1], ['weight' => 1000, 'qty' => 1]];
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $shares = ComboAllocation::forLine(5000, 1_000_000, 5_000_000_000, $children);
        $grown = memory_get_peak_usage() - $before;

        $this->assertSame([3_334_000_000, 833_000_000, 833_000_000], $shares);
        $this->assertSame(5_000_000_000, array_sum($shares));
        $this->assertLessThan(1_000_000, $grown, "the split grew memory by {$grown} bytes");

        // A huge item quantity inside the combo too.
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $shares = ComboAllocation::forLine(5000, 3, 15000, [['weight' => 1, 'qty' => 2_000_000], ['weight' => 3000, 'qty' => 1]]);
        $this->assertLessThan(1_000_000, memory_get_peak_usage() - $before);
        $this->assertSame(15000, array_sum($shares));
    }

    public function test_c18_the_split_gives_the_same_shares_as_one_entry_per_unit(): void
    {
        mt_srand(18);
        for ($case = 0; $case < 400; $case++) {
            $children = [];
            for ($c = 0, $n = mt_rand(1, 4); $c < $n; $c++) {
                // Tiny weights next to big ones push some cases onto the cumulative fallback.
                $weight = mt_rand(0, 3) === 0 ? mt_rand(0, 3) : mt_rand(0, 4000);
                $qty = mt_rand(0, 4) === 0 ? mt_rand(1, 9) / 4 : mt_rand(1, 4);
                $children[] = ['weight' => $weight, 'qty' => $qty];
            }
            $lineQty = mt_rand(0, 4) === 0 ? mt_rand(1, 9) / 2 : mt_rand(1, 6);
            $unit = mt_rand(0, 9000);
            $paid = mt_rand(0, 3) === 0 ? mt_rand(0, 9) : mt_rand(0, 60000);
            $this->assertSame(self::expanded($unit, $lineQty, $paid, $children), ComboAllocation::forLine($unit, $lineQty, $paid, $children),
                'case '.$case.': '.json_encode([$unit, $lineQty, $paid, $children]));
        }
    }

    public function test_c18_a_device_line_over_9999_is_flagged_never_refused(): void
    {
        $this->p4Device('mdev_fix2_c18');
        $line = fn (int $qty): array => ['product_id' => $this->fries, 'qty' => $qty, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000 * $qty];
        $push = fn (int $qty): array => $this->p4Push('mdev_fix2_c18', [$this->p4Event('order.create', $this->p4Order([$line($qty)]))])->json('data.results.0');

        $ok = $push(9999);
        $this->assertSame('processed', $ok['status'], (string) json_encode($ok));
        $this->assertNotContains('line_qty', array_column($ok['result']['pricing_check']['failures'] ?? [], 'code'));

        $big = $push(10000);
        $this->assertSame('processed', $big['status'], (string) json_encode($big));
        $this->assertFalse($big['result']['pricing_check']['match']);
        $failure = collect($big['result']['pricing_check']['failures'])->firstWhere('code', 'line_qty');
        $this->assertSame(['line_index' => 0, 'qty_at_most' => 9999], $failure['expected']);
        $this->assertSame(['line_index' => 0, 'qty' => 10000], $failure['actual']);
    }

    /**
     * The fix order 1 algorithm, one entry per unit (the reference).
     *
     * @param  list<array{weight: int, qty: int|float}>  $children
     * @return list<int>
     */
    private static function expanded(int $unitPrice, int|float $lineQty, int $paid, array $children): array
    {
        $unitWeights = [];
        $owner = [];
        foreach ($children as $index => $child) {
            $qty = (float) $child['qty'];
            if ($qty >= 1 && $qty == floor($qty)) {
                for ($k = 0; $k < (int) $qty; $k++) {
                    $unitWeights[] = (int) $child['weight'];
                    $owner[] = $index;
                }
            } else {
                $unitWeights[] = (int) round($child['weight'] * $qty);
                $owner[] = $index;
            }
        }
        $qty = (float) $lineQty;
        if ($qty >= 1 && $qty == floor($qty)) {
            $weights = [];
            $owners = [];
            for ($copy = 0; $copy < (int) $qty; $copy++) {
                array_push($weights, ...$unitWeights);
                array_push($owners, ...$owner);
            }
            $shares = array_fill(0, count($children), 0);
            foreach (ComboAllocation::split($paid, $weights) as $unit => $share) {
                $shares[$owners[$unit]] += $share;
            }

            return $shares;
        }
        $perOne = array_fill(0, count($children), 0);
        foreach (ComboAllocation::split($unitPrice, $unitWeights) as $unit => $share) {
            $perOne[$owner[$unit]] += $share;
        }

        return ComboAllocation::split($paid, $perOne);
    }
}
