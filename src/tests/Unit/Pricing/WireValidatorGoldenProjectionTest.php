<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\WireValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Tests\TestCase;

final class WireValidatorGoldenProjectionTest extends TestCase
{
    use RefreshDatabase;

    /** @throws JsonException */
    public function test_a_lawful_wire_projection_derived_from_a_vendored_golden_is_clean(): void
    {
        $contents = file_get_contents(base_path(
            'tests/Fixtures/pricing_goldens/v0.2.0/order_percent_discount.json',
        ));
        $this->assertNotFalse($contents);
        $vector = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $input = $vector['input'];
        $expected = $vector['expected'];

        foreach ($input['taxes'] as $index => $tax) {
            DB::table('pos_taxes')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => $tax['name'],
                'rate_percent' => $tax['ratePercent'],
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => '2026-08-15 00:00:00',
                'updated_at' => '2026-08-15 00:00:00',
            ]);
        }

        $lines = array_map(static fn (array $line): array => [
            'product_id' => $line['productId'],
            'qty' => $line['qty'],
            'unit_price_baisas' => $line['unitPriceBaisas'],
            'line_total_baisas' => $line['unitPriceBaisas'] * $line['qty'],
        ], $input['lines']);
        $discounts = $expected['discountTotalBaisas'] === 0 ? [] : [[
            'name' => $input['orderDiscount']['label'],
            'amount_baisas' => $expected['discountTotalBaisas'],
        ]];
        $order = [
            'pricing_engine' => 1,
            'uuid' => (string) Str::uuid(),
            'order_type' => 'quick',
            'source' => 'handheld',
            'opened_at' => $input['now'],
            'subtotal_baisas' => $expected['rawSubtotalBaisas'],
            'discount_total_baisas' => $expected['discountTotalBaisas'],
            'comp_total_baisas' => $expected['compTotalBaisas'],
            'tax_total_baisas' => $expected['taxTotalBaisas'],
            'grand_total_baisas' => $expected['grandTotalBaisas'],
            'lines' => $lines,
            'discounts' => $discounts,
            'comps' => [],
        ];

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.2.0',
            'match' => true,
            'failures' => [],
        ], (new WireValidator)->validate($order, 100, 10, 99));
    }
}
