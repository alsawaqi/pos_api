<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Recipes\PrepExploder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-4 — the explode rule (data contract): a line of prep P with
 * quantity q becomes q × c.quantity ÷ P.prep_yield_quantity of each
 * component c, recursively (at most 3 prep levels), same raw ingredients
 * merged, exact until the end (4-decimal quantities, 6-decimal costs). Bad
 * data (cycle, 4th level, no yield, another company) is skipped, never looped
 * on and never fatal.
 */
class LaunchP3PrepExploderTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use RefreshDatabase;

    /**
     * @param  list<array{0: int, 1: string, 2?: string}>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(array $lines): array
    {
        return array_map(static fn (array $l): array => ['ingredient_id' => $l[0], 'quantity' => $l[1], 'unit' => $l[2] ?? null], $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $exploded
     * @return array<int, string>
     */
    private function quantities(array $exploded): array
    {
        $out = [];
        foreach ($exploded as $line) {
            $out[$line['ingredient_id']] = $line['quantity'];
        }

        return $out;
    }

    public function test_a_prep_line_explodes_into_its_raw_ingredients_and_merges_with_direct_lines(): void
    {
        $this->seedPrepKitchen();
        $exploder = new PrepExploder(100);

        $out = $exploder->explode($this->lines([
            [self::CHICKEN, '150', 'g'], [self::BREAD, '1', 'piece'], [self::SAUCE, '30', 'ml'], [self::GARLIC, '5', 'g'],
        ]));

        // First-appearance order; garlic merges 30×200÷1000 = 6 with the direct 5.
        $this->assertSame([self::CHICKEN, self::BREAD, self::GARLIC, self::OIL, self::LEMON], array_column($out, 'ingredient_id'));
        $this->assertSame([
            self::CHICKEN => '150.0000', self::BREAD => '1.0000', self::GARLIC => '11.0000', self::OIL => '21.0000', self::LEMON => '3.0000',
        ], $this->quantities($out));
        // Exploded lines take the raw ingredient's base unit and live cost.
        $oil = $out[3];
        $this->assertSame('ml', $oil['unit']);
        $this->assertSame('0.001500', $oil['unit_cost']);
        $this->assertNotContains(self::SAUCE, array_column($out, 'ingredient_id'));
        $this->assertSame([], $exploder->problems());
    }

    public function test_the_cost_of_a_prep_flows_through_its_recipe(): void
    {
        $this->seedPrepKitchen();
        $exploder = new PrepExploder(100);

        // cost(sauce) per ml = Σ(c.quantity × cost(c)) ÷ yield = 1.55 ÷ 1000.
        $this->assertSame('0.001550', $exploder->cost($this->lines([[self::SAUCE, '1']])));
        $this->assertSame('0.706500', $exploder->cost($this->lines([
            [self::CHICKEN, '150'], [self::BREAD, '1'], [self::SAUCE, '30'], [self::GARLIC, '5'],
        ])));
    }

    public function test_quantities_round_only_at_the_end(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(20, 'Sugar', 'g', '0.001000'),
            $this->ingredientRow(21, 'Water', 'ml', '0'),
            $this->ingredientRow(22, 'Syrup', 'ml', '0', isPrep: true, yield: '3'),
            $this->ingredientRow(23, 'Glaze', 'g', '0', isPrep: true, yield: '3'),
        ]);
        $this->prepRecipe(22, [[20, '1'], [21, '2']]);
        $this->prepRecipe(23, [[20, '1']]);

        $out = $this->quantities((new PrepExploder(100))->explode($this->lines([[22, '1'], [23, '1']])));

        // 1/3 + 1/3 of sugar is 0.6667 (rounding each third first would give 0.6666).
        $this->assertSame('0.6667', $out[20]);
        $this->assertSame('0.6667', $out[21]);

        // A batch multiplier is applied before rounding too: 7 × 1/3 = 2.3333.
        $this->assertSame('2.3333', $this->quantities((new PrepExploder(100))->explode($this->lines([[22, '1']]), 7))[20]);
    }

    public function test_three_prep_levels_explode_and_a_fourth_is_skipped(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(31, 'Level 1', 'g', '0', isPrep: true, yield: '10'),
            $this->ingredientRow(32, 'Level 2', 'g', '0', isPrep: true, yield: '10'),
            $this->ingredientRow(33, 'Level 3', 'g', '0', isPrep: true, yield: '10'),
            $this->ingredientRow(34, 'Level 4', 'g', '0', isPrep: true, yield: '10'),
        ]);
        $this->prepRecipe(31, [[32, '10'], [self::GARLIC, '1']]);
        $this->prepRecipe(32, [[33, '10'], [self::OIL, '1']]);
        $this->prepRecipe(33, [[self::LEMON, '10'], [34, '10']]);
        $this->prepRecipe(34, [[self::CHICKEN, '10']]);

        $exploder = new PrepExploder(100);
        $out = $this->quantities($exploder->explode($this->lines([[31, '10']])));

        $this->assertSame([self::LEMON => '10.0000', self::OIL => '1.0000', self::GARLIC => '1.0000'], $out);
        $this->assertArrayNotHasKey(self::CHICKEN, $out);
        $this->assertSame([['prep_ingredient_id' => 34, 'reason' => PrepExploder::PROBLEM_TOO_DEEP, 'path' => [31, 32, 33]]], $exploder->problems());
    }

    public function test_a_cycle_is_cut_instead_of_looping(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(41, 'Cycle A', 'g', '0', isPrep: true, yield: '10'),
            $this->ingredientRow(42, 'Cycle B', 'g', '0', isPrep: true, yield: '10'),
            $this->ingredientRow(43, 'Self', 'g', '0', isPrep: true, yield: '10'),
        ]);
        $this->prepRecipe(41, [[42, '10'], [self::GARLIC, '2']]);
        $this->prepRecipe(42, [[41, '10'], [self::OIL, '3']]);
        $this->prepRecipe(43, [[43, '5'], [self::LEMON, '4']]);

        $exploder = new PrepExploder(100);
        $out = $this->quantities($exploder->explode($this->lines([[41, '10'], [43, '10']])));

        $this->assertSame([self::OIL => '3.0000', self::GARLIC => '2.0000', self::LEMON => '4.0000'], $out);
        $this->assertSame([PrepExploder::PROBLEM_CYCLE, PrepExploder::PROBLEM_CYCLE], array_column($exploder->problems(), 'reason'));
        $this->assertSame([41, 43], array_column($exploder->problems(), 'prep_ingredient_id'));
    }

    public function test_a_prep_without_a_yield_or_from_another_company_deducts_nothing(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(51, 'No yield', 'ml', '0', isPrep: true, yield: null),
            $this->ingredientRow(52, 'Zero yield', 'ml', '0', isPrep: true, yield: '0'),
            $this->ingredientRow(53, 'Foreign prep', 'ml', '0', isPrep: true, yield: '10', companyId: 200),
            $this->ingredientRow(54, 'Foreign raw', 'g', '9.000000', companyId: 200),
            $this->ingredientRow(55, 'Mixed prep', 'ml', '0', isPrep: true, yield: '10'),
        ]);
        $this->prepRecipe(51, [[self::GARLIC, '1']]);
        $this->prepRecipe(52, [[self::GARLIC, '1']]);
        $this->prepRecipe(53, [[self::GARLIC, '1']]);
        $this->prepRecipe(55, [[54, '10'], [self::OIL, '10']]);

        $exploder = new PrepExploder(100);
        $out = $this->quantities($exploder->explode($this->lines([[51, '10'], [52, '10'], [53, '10'], [55, '10']])));

        $this->assertSame([self::OIL => '10.0000'], $out);
        $this->assertSame(
            [PrepExploder::PROBLEM_NO_YIELD, PrepExploder::PROBLEM_NO_YIELD, PrepExploder::PROBLEM_OTHER_COMPANY, PrepExploder::PROBLEM_OTHER_COMPANY],
            array_column($exploder->problems(), 'reason'),
        );
    }

    public function test_a_soft_deleted_prep_still_explodes_and_raw_lines_pass_through(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_ingredients')->where('id', self::SAUCE)->update(['deleted_at' => now()]);

        $exploder = new PrepExploder(100);
        $this->assertTrue($exploder->isPrep(self::SAUCE));
        $this->assertFalse($exploder->isPrep(self::GARLIC));
        $this->assertSame(
            [self::GARLIC => '20.0000', self::OIL => '70.0000', self::LEMON => '10.0000', 999 => '2.5000'],
            $this->quantities($exploder->explode($this->lines([[self::SAUCE, '100'], [999, '2.5', 'g']]))),
        );
    }

    public function test_a_recipe_without_prep_items_comes_back_unchanged(): void
    {
        $this->seedPrepKitchen();

        $out = (new PrepExploder(100))->explode($this->lines([[self::CHICKEN, '0.0003', 'kg'], [self::BREAD, '2', 'piece']]));

        $this->assertSame([
            ['ingredient_id' => self::CHICKEN, 'quantity' => '0.0003', 'unit' => 'kg', 'unit_cost' => '0.004000', 'group' => '', 'line' => 0],
            ['ingredient_id' => self::BREAD, 'quantity' => '2.0000', 'unit' => 'piece', 'unit_cost' => '0.050000', 'group' => '', 'line' => 1],
        ], $out);
    }
}
