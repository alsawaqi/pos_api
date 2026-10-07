<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\WireValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WireValidatorMixedCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_in_range_indexed_rows_are_informational_and_order_checks_still_run(): void
    {
        Log::spy();
        $order = $this->mixedOrder();

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.4.0',
            'match' => true,
            'failures' => [],
            'informational' => ['mixed_cart_unverifiable'],
        ], $this->check($order));
        Log::shouldNotHaveReceived('warning');

        $s1 = $order;
        $s1['lines'][0]['unit_price_baisas'] = 999;
        $this->assertSame(['line_total'], $this->failureCodes($this->check($s1)));

        $s3 = $order;
        $s3['grand_total_baisas'] = 2001;
        $this->assertSame(['identity_zero'], $this->failureCodes($this->check($s3)));

        $s7 = $order;
        $s7['discount_total_baisas'] = 3500;
        $s7['comp_total_baisas'] = 500;
        $s7['grand_total_baisas'] = -1000;
        $this->assertSame(['bounds'], $this->failureCodes($this->check($s7)));

        $s8 = $order;
        $s8['order_type'] = 'delivery';
        $this->assertSame(['delivery_tax'], $this->failureCodes($this->check($s8)));

        // LAUNCH-P4 — taxes apply to a VAT-registered merchant (priced on top here).
        $this->registerVat();
        DB::table('pos_taxes')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'VAT',
            'rate_percent' => 5,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => '2026-08-15 00:00:00',
            'updated_at' => '2026-08-15 00:00:00',
        ]);
        $this->assertSame(['tax_recompute'], $this->failureCodes($this->check($order)));
    }

    public function test_b_out_of_range_index_is_still_informational_only(): void
    {
        Log::spy();
        $order = $this->mixedOrder();
        $order['comps'][0]['line_index'] = 7;

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.4.0',
            'match' => true,
            'failures' => [],
            'informational' => ['mixed_cart_unverifiable'],
        ], $this->check($order));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_c_fingerprint_without_indexed_rows_still_skips_broken_row_sums(): void
    {
        Log::spy();
        $order = $this->mixedOrder([
            'discounts' => [[
                'name' => 'Order',
                'amount_baisas' => 100,
            ]],
            'comps' => [[
                'comp_reason_id' => 2,
                'amount_baisas' => 500,
            ]],
        ]);

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.4.0',
            'match' => true,
            'failures' => [],
            'informational' => ['mixed_cart_unverifiable'],
        ], $this->check($order));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_d_equal_main_pos_line_sum_runs_the_full_pipeline_without_information(): void
    {
        $clean = $this->order(['source' => 'main_pos']);
        $cleanResult = $this->check($clean);
        $this->assertTrue($cleanResult['match']);
        $this->assertArrayNotHasKey('informational', $cleanResult);

        $brokenRows = $this->order([
            'source' => 'main_pos',
            'discount_total_baisas' => 500,
            'grand_total_baisas' => 1500,
            'discounts' => [[
                'name' => 'Line',
                'line_index' => 0,
                'amount_baisas' => 100,
            ]],
        ]);
        $brokenResult = $this->check($brokenRows);
        $this->assertSame(['discount_rows'], $this->failureCodes($brokenResult));
        $this->assertArrayNotHasKey('informational', $brokenResult);
    }

    public function test_e_handheld_line_shortfall_fails_s2_normally(): void
    {
        $result = $this->check($this->order([
            'subtotal_baisas' => 3000,
            'grand_total_baisas' => 3000,
        ]));

        $this->assertSame(['subtotal'], $this->failureCodes($result));
        $this->assertArrayNotHasKey('informational', $result);
    }

    public function test_f_main_pos_line_excess_fails_s2_normally(): void
    {
        $result = $this->check($this->order([
            'source' => 'main_pos',
            'subtotal_baisas' => 1500,
            'grand_total_baisas' => 1500,
        ]));

        $this->assertSame(['subtotal'], $this->failureCodes($result));
        $this->assertArrayNotHasKey('informational', $result);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mixedOrder(array $overrides = []): array
    {
        return array_replace($this->order([
            'source' => 'main_pos',
            'subtotal_baisas' => 3000,
            'discount_total_baisas' => 500,
            'comp_total_baisas' => 500,
            'grand_total_baisas' => 2000,
            'discounts' => [[
                'name' => 'Snapshot line',
                'line_index' => 0,
                'amount_baisas' => 123,
            ]],
            'comps' => [[
                'comp_reason_id' => 2,
                'line_index' => 0,
                'qty' => 1,
                'amount_baisas' => 500,
            ]],
        ]), $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return array_replace([
            'pricing_engine' => 1,
            'uuid' => (string) Str::uuid(),
            'order_type' => 'quick',
            'source' => 'handheld',
            'opened_at' => '2026-08-16T12:00:00+00:00',
            'subtotal_baisas' => 2000,
            'discount_total_baisas' => 0,
            'comp_total_baisas' => 0,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 2000,
            'lines' => [[
                'product_id' => 1,
                'qty' => 2,
                'unit_price_baisas' => 1000,
                'line_total_baisas' => 2000,
            ]],
            'discounts' => [],
            'comps' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function check(array $order): array
    {
        return (new WireValidator)->validate($order, 100, 10, 99);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function failureCodes(array $result): array
    {
        return array_column($result['failures'], 'code');
    }
}
