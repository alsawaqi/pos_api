<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Pricing\WireValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WireValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_five_canonical_shapes_and_setting_first_gate(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'pricing_enforcement',
            'value' => json_encode('off'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withoutFlag = $this->order();
        unset($withoutFlag['pricing_engine']);
        $this->assertSame(
            ['checked' => false, 'reason' => 'off'],
            $this->check($withoutFlag),
        );

        DB::table('pos_company_settings')->where('company_id', 100)->delete();
        $this->assertSame(
            ['checked' => false, 'reason' => 'no_flag'],
            $this->check($withoutFlag),
        );

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.3.0',
            'match' => true,
            'failures' => [],
        ], $this->check($this->order()));

        $mismatch = $this->order(['grand_total_baisas' => 2001]);
        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.3.0',
            'match' => false,
            'failures' => [[
                'code' => 'identity_zero',
                'expected' => 2000,
                'actual' => 2001,
            ]],
        ], $this->check($mismatch));

        Schema::drop('pos_company_settings');
        $this->assertSame(
            ['checked' => false, 'reason' => 'error'],
            $this->check($this->order()),
        );
    }

    public function test_s1_line_total_and_quantity_integrality_contract(): void
    {
        $bad = $this->order();
        $bad['lines'][0]['unit_price_baisas'] = 999;
        $this->assertSame(['line_total'], $this->failureCodes($this->check($bad)));

        $integralFloat = $this->order();
        $integralFloat['lines'][0]['qty'] = 2.0;
        $integral = $this->check($integralFloat);
        $this->assertTrue($integral['match']);
        $this->assertArrayNotHasKey('informational', $integral);

        Log::spy();
        $fractional = $this->order();
        $fractional['lines'][0] = [
            'product_id' => 1,
            'qty' => 2.5,
            'unit_price_baisas' => 800,
            'line_total_baisas' => 2000,
        ];
        $result = $this->check($fractional);
        $this->assertTrue($result['match']);
        $this->assertSame([], $result['failures']);
        $this->assertSame(['line_qty_non_integer'], $result['informational']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_s2_subtotal_and_main_pos_demo_line_tolerance(): void
    {
        $bad = $this->order([
            'subtotal_baisas' => 2001,
            'grand_total_baisas' => 2001,
        ]);
        $this->assertSame(['subtotal'], $this->failureCodes($this->check($bad)));

        $machineDemo = $this->order([
            'source' => 'main_pos',
            'subtotal_baisas' => 2500,
            'grand_total_baisas' => 2500,
        ]);
        $this->assertTrue($this->check($machineDemo)['match']);
    }

    public function test_s3_observes_the_exact_identity(): void
    {
        $this->assertSame(
            ['identity_zero'],
            $this->failureCodes($this->check($this->order(['grand_total_baisas' => 2001]))),
        );
    }

    public function test_s4_rows_and_clamp_engaged_row_omission(): void
    {
        $missing = $this->order([
            'discount_total_baisas' => 100,
            'grand_total_baisas' => 1900,
        ]);
        $this->assertSame(['discount_rows'], $this->failureCodes($this->check($missing)));

        $clamped = $this->order([
            'discount_total_baisas' => 2000,
            'grand_total_baisas' => 0,
            'discounts' => [
                ['name' => 'Line', 'line_index' => 0, 'amount_baisas' => 200],
                ['name' => 'Offer', 'offer_id' => 9, 'line_index' => 0, 'amount_baisas' => 2000],
            ],
        ]);
        $this->assertTrue($this->check($clamped)['match']);

        $notClamped = $this->order([
            'discount_total_baisas' => 1000,
            'grand_total_baisas' => 1000,
            'discounts' => [
                ['name' => 'Line', 'line_index' => 0, 'amount_baisas' => 200],
                ['name' => 'Offer', 'offer_id' => 9, 'line_index' => 0, 'amount_baisas' => 1300],
            ],
        ]);
        $this->assertSame(['discount_rows'], $this->failureCodes($this->check($notClamped)));
    }

    public function test_s5_order_row_splitback_is_classified(): void
    {
        $bad = $this->order([
            'discount_total_baisas' => 100,
            'grand_total_baisas' => 1900,
            'discounts' => [
                ['name' => 'Line', 'line_index' => 0, 'amount_baisas' => 20],
                ['name' => 'Order', 'amount_baisas' => 90],
            ],
        ]);

        // The wrong order row necessarily also breaks S4's row sum; S5 keeps
        // its own code so telemetry identifies the split-back defect.
        $this->assertSame(
            ['discount_rows', 'order_row_splitback'],
            $this->failureCodes($this->check($bad)),
        );
    }

    public function test_s6_accepts_partial_qty_gift_nets_clamp_and_both_row_orders(): void
    {
        $partial = $this->order([
            'subtotal_baisas' => 1100,
            'discount_total_baisas' => 99,
            'comp_total_baisas' => 501,
            'grand_total_baisas' => 500,
            'lines' => [[
                'product_id' => 12,
                'qty' => 2,
                'unit_price_baisas' => 550,
                'line_total_baisas' => 1100,
            ]],
            'discounts' => [[
                'name' => 'Nine percent',
                'line_index' => 0,
                'amount_baisas' => 99,
            ]],
            'comps' => [[
                'comp_reason_id' => 2,
                'line_index' => 0,
                'qty' => 1,
                'amount_baisas' => 501,
            ]],
        ]);
        $this->assertTrue($this->check($partial)['match']);

        $badPartial = $partial;
        $badPartial['comps'][0]['amount_baisas'] = 500;
        $this->assertSame(['comp_pipeline'], $this->failureCodes($this->check($badPartial)));

        $giftNet = $this->order([
            'subtotal_baisas' => 1000,
            'discount_total_baisas' => 200,
            'comp_total_baisas' => 800,
            'grand_total_baisas' => 0,
            'lines' => [[
                'product_id' => 1,
                'qty' => 1,
                'unit_price_baisas' => 1000,
                'line_total_baisas' => 1000,
            ]],
            'discounts' => [[
                'name' => 'Line',
                'line_index' => 0,
                'amount_baisas' => 200,
            ]],
            'comps' => [[
                'is_gift' => true,
                'line_index' => 0,
                'amount_baisas' => 800,
            ]],
        ]);
        $this->assertTrue($this->check($giftNet)['match']);

        $giftClamp = $this->order([
            'subtotal_baisas' => 3500,
            'discount_total_baisas' => 1000,
            'comp_total_baisas' => 2500,
            'grand_total_baisas' => 0,
            'lines' => [
                ['product_id' => 21, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_total_baisas' => 3000],
                ['product_id' => 22, 'qty' => 1, 'unit_price_baisas' => 500, 'line_total_baisas' => 500],
            ],
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 1000]],
            'comps' => [['is_gift' => true, 'line_index' => 0, 'amount_baisas' => 2500]],
        ]);
        $this->assertTrue($this->check($giftClamp)['match']);

        $giftRow = ['is_gift' => true, 'line_index' => 0, 'amount_baisas' => 1000];
        $managerRow = ['comp_reason_id' => 2, 'line_index' => 1, 'amount_baisas' => 1000];
        $mixed = $this->order([
            'comp_total_baisas' => 2000,
            'grand_total_baisas' => 0,
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000],
                ['product_id' => 2, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000],
            ],
            'comps' => [$managerRow, $giftRow],
        ]);
        $this->assertTrue($this->check($mixed)['match'], 'machine reasoned-first order');
        $mixed['comps'] = [$giftRow, $managerRow];
        $this->assertTrue($this->check($mixed)['match'], 'handheld gifts-first order');
    }

    public function test_s7_total_bounds_are_observed(): void
    {
        $bad = $this->order([
            'discount_total_baisas' => 2100,
            'grand_total_baisas' => -100,
            'discounts' => [['name' => 'Order', 'amount_baisas' => 2100]],
        ]);
        $this->assertSame(['bounds'], $this->failureCodes($this->check($bad)));
    }

    public function test_s9_reports_only_line_index_and_skips_dependent_checks(): void
    {
        $bad = $this->order([
            'discounts' => [[
                'name' => 'Bad index',
                'line_index' => 7,
                'amount_baisas' => 500,
            ]],
            'comps' => [[
                'comp_reason_id' => 2,
                'line_index' => 9,
                'amount_baisas' => 500,
            ]],
        ]);

        $this->assertSame(['line_index'], $this->failureCodes($this->check($bad)));

        $nonInteger = $this->order([
            'discounts' => [[
                'name' => 'Float index',
                'line_index' => 0.0,
                'amount_baisas' => 0,
            ]],
        ]);
        $this->assertSame(['line_index'], $this->failureCodes($this->check($nonInteger)));

    }

    public function test_s8_delivery_requires_zero_tax_and_no_line_or_offer_rows(): void
    {
        $clean = $this->order(['order_type' => 'delivery']);
        $this->assertTrue($this->check($clean)['match']);

        $bad = $this->order([
            'order_type' => 'delivery',
            'tax_total_baisas' => 1,
            'grand_total_baisas' => 2001,
        ]);
        $this->assertSame(['delivery_tax'], $this->failureCodes($this->check($bad)));

        foreach ([
            ['name' => 'Line', 'line_index' => 0, 'amount_baisas' => 100],
            ['name' => 'Offer', 'offer_id' => 9, 'line_index' => 0, 'amount_baisas' => 100],
        ] as $forbiddenRow) {
            $withRow = $this->order([
                'order_type' => 'delivery',
                'discount_total_baisas' => 100,
                'grand_total_baisas' => 1900,
                'discounts' => [$forbiddenRow],
            ]);
            $this->assertSame(['delivery_tax'], $this->failureCodes($this->check($withRow)));
        }
    }

    public function test_c1_recomputes_active_taxes_and_suppresses_on_drift(): void
    {
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

        $taxed = $this->order([
            'tax_total_baisas' => 100,
            'grand_total_baisas' => 2100,
        ]);
        $this->assertTrue($this->check($taxed)['match']);

        $wrong = $taxed;
        $wrong['tax_total_baisas'] = 99;
        $wrong['grand_total_baisas'] = 2099;
        $this->assertSame(['tax_recompute'], $this->failureCodes($this->check($wrong)));

        DB::table('pos_taxes')->update(['updated_at' => '2026-08-17 00:00:00']);
        $suppressed = $this->check($wrong);
        $this->assertTrue($suppressed['match']);
        $this->assertSame([], $suppressed['failures']);
        $this->assertSame(['tax_recompute'], $suppressed['suppressed']);

        foreach ([
            'created_after_open' => [
                'created_at' => '2026-08-17 00:00:00',
                'updated_at' => '2026-08-15 00:00:00',
                'deleted_at' => null,
            ],
            'updated_at_null' => [
                'created_at' => '2026-08-15 00:00:00',
                'updated_at' => null,
                'deleted_at' => null,
            ],
            'deleted_after_open' => [
                'created_at' => '2026-08-15 00:00:00',
                'updated_at' => '2026-08-15 00:00:00',
                'deleted_at' => '2026-08-17 00:00:00',
            ],
        ] as $case => $timestamps) {
            DB::table('pos_taxes')->update($timestamps);
            $guarded = $this->check($wrong);
            $this->assertTrue($guarded['match'], $case);
            $this->assertSame([], $guarded['failures'], $case);
            $this->assertSame(['tax_recompute'], $guarded['suppressed'], $case);
        }
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
