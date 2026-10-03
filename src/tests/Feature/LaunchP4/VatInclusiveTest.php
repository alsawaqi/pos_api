<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Support\Money;
use App\Support\Pricing\CompanyTaxPolicy;
use App\Support\Pricing\PricingInput;
use App\Support\Pricing\PricingLine;
use App\Support\Pricing\TaxLineResult;
use App\Support\Pricing\TaxSpec;
use App\Support\Pricing\Totals;
use App\Support\Pricing\VectorLoader;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\LaunchP4Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P4 A2 — VAT per merchant (owner decision 1).
 *
 *  - The pricing port prices inclusive taxes like mithqal_pricing v0.3.0:
 *    each row round(G × rᵢ / (100 + R)), rounded per row, grand = G.
 *  - Effective taxes: the active rows of a VAT-registered merchant, none
 *    for an unregistered one; "menu prices include VAT" defaults to true.
 *  - The device config carries company.tax and only the effective taxes.
 *  - order.create stamps prices_include_tax and checks the inclusive
 *    identity (subtotal − discount − comp = grand); WireValidator recomputes
 *    the inclusive tax.
 *  - QR checkout prices VAT inside the menu price; the table bill header
 *    keeps an inclusive bill's tax inside its total after adjustments.
 */
final class VatInclusiveTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
    }

    public function test_the_pricing_port_takes_inclusive_tax_out_of_the_taxed_gross_base_row_by_row(): void
    {
        $taxes = [new TaxSpec('VAT', 5.0, 'ضريبة القيمة المضافة'), new TaxSpec('Municipality', 2.0)];
        $price = fn (bool $inclusive, bool $delivery = false) => Totals::priceOrder(new PricingInput(
            lines: [new PricingLine(unitPriceBaisas: 1000, qty: 1, productId: 1)],
            now: new DateTimeImmutable('2026-10-03 09:00:00'),
            taxes: $taxes, isDeliveryProvider: $delivery, branchId: 10, pricesIncludeTax: $inclusive,
        ));

        $inclusive = $price(true);
        $this->assertSame([47, 19], array_map(static fn (TaxLineResult $l): int => $l->amountBaisas, $inclusive->taxLines));
        $this->assertSame(66, $inclusive->taxTotalBaisas);
        $this->assertSame(1000, $inclusive->taxedBaseBaisas);
        $this->assertSame(1000, $inclusive->grandTotalBaisas);
        $this->assertTrue($inclusive->pricesIncludeTax);

        $exclusive = $price(false);
        $this->assertSame(70, $exclusive->taxTotalBaisas);
        $this->assertSame(1070, $exclusive->grandTotalBaisas);

        $delivery = $price(true, true);
        $this->assertSame(0, $delivery->taxTotalBaisas);
        $this->assertSame(1000, $delivery->grandTotalBaisas);

        // Golden-vector JSON carries the flag as pricesIncludeTax.
        $loaded = Totals::priceOrder(VectorLoader::inputFromArray([
            'now' => '2026-10-03T09:00:00+04:00', 'branchId' => 10, 'pricesIncludeTax' => true,
            'lines' => [['unitPriceBaisas' => 1000, 'qty' => 1, 'productId' => 1]],
            'taxes' => [['name' => 'VAT', 'ratePercent' => 5], ['name' => 'Municipality', 'ratePercent' => 2]],
        ]));
        $this->assertSame([66, 1000], [$loaded->taxTotalBaisas, $loaded->grandTotalBaisas]);
    }

    public function test_an_unregistered_merchant_charges_no_vat_and_a_registered_one_defaults_to_inclusive(): void
    {
        $this->p4Tax();
        $policy = CompanyTaxPolicy::for(100);
        $this->assertFalse($policy->vatRegistered);
        $this->assertCount(0, $policy->effectiveTaxes());
        $this->assertFalse($policy->pricesIncludeTax());

        DB::table('pos_companies')->insert(['id' => 100, 'vat_number' => ' OM1100000001 ', 'vat_registered_at' => '2026-01-01']);
        $policy = CompanyTaxPolicy::for(100);
        $this->assertSame(['vat_registered' => true, 'prices_include_vat' => true, 'vat_number' => 'OM1100000001'], $policy->deviceBlock());
        $this->assertCount(1, $policy->effectiveTaxes());
        $this->assertTrue($policy->pricesIncludeTax());

        $this->registerVat(100, false);
        $this->assertFalse(CompanyTaxPolicy::for(100)->pricesIncludeTax());
        $this->assertSame([5.0], array_map(static fn (TaxSpec $t): float => $t->ratePercent, CompanyTaxPolicy::for(100)->taxSpecs()));
    }

    public function test_the_device_config_carries_the_vat_policy_and_only_the_effective_taxes(): void
    {
        $this->p4Device('mdev_p4_vat');
        $vat = $this->p4Tax();
        $old = $this->p4Tax('Old levy', '1.00', null, false);

        $this->withToken('mdev_p4_vat')->getJson('/api/v1/device/config')->assertOk()
            ->assertJsonPath('data.company.tax', ['vat_registered' => false, 'prices_include_vat' => true, 'vat_number' => null])
            ->assertJsonPath('data.taxes', []);

        $this->registerVat(100, true);
        $full = $this->withToken('mdev_p4_vat')->getJson('/api/v1/device/config')->assertOk();
        $full->assertJsonPath('data.company.tax', ['vat_registered' => true, 'prices_include_vat' => true, 'vat_number' => 'OM1100000001']);
        $this->assertSame([$vat], array_column($full->json('data.taxes'), 'id'));
        $this->assertSame('ضريبة القيمة المضافة', $full->json('data.taxes.0.name_ar'));

        // A delta after nothing changed still carries the effective set, and
        // purges every tax that does not apply.
        $delta = $this->withToken('mdev_p4_vat')
            ->getJson('/api/v1/device/config/delta?since='.urlencode(now()->toIso8601String()))->assertOk();
        $this->assertSame([$vat], array_column($delta->json('data.taxes'), 'id'));
        $this->assertSame([$old], $delta->json('data.deleted.taxes'));
    }

    public function test_an_inclusive_order_is_stamped_and_checked_with_the_inclusive_identity(): void
    {
        $this->registerVat(100, true);
        $this->p4Tax();
        $product = $this->p4Product('Latte');
        $this->p4Device('mdev_p4_incl');
        $line = ['product_id' => $product, 'qty' => 2, 'unit_price_baisas' => 1000, 'line_total_baisas' => 2000];

        // 2.000 with VAT 5% inside: round(2000 × 5 / 105) = 95.
        $order = $this->p4Order([$line], 95, true);
        $result = $this->p4Push('mdev_p4_incl', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame(['checked' => true, 'engine' => 'php-mithqal/0.3.0', 'match' => true, 'failures' => []], $result['result']['pricing_check']);
        $row = DB::table('pos_orders')->where('uuid', $order['uuid'])->first();
        $this->assertTrue((bool) $row->prices_include_tax);
        $this->assertSame([2000, 95, 2000], [Money::toBaisas($row->subtotal), Money::toBaisas($row->tax_total), Money::toBaisas($row->grand_total)]);

        // A wrong inclusive tax is flagged, never refused.
        $wrong = $this->p4Order([$line], 100, true);
        $result = $this->p4Push('mdev_p4_incl', [$this->p4Event('order.create', $wrong)])->json('data.results.0');
        $this->assertSame('processed', $result['status']);
        $this->assertSame('tax_recompute', $result['result']['pricing_check']['failures'][0]['code']);
        $this->assertSame([95, 100], [$result['result']['pricing_check']['failures'][0]['expected'], $result['result']['pricing_check']['failures'][0]['actual']]);

        // An order without the flag keeps the exclusive identity and stamp.
        $exclusive = $this->p4Order([$line], 0);
        $this->p4Push('mdev_p4_incl', [$this->p4Event('order.create', $exclusive)]);
        $this->assertFalse((bool) DB::table('pos_orders')->where('uuid', $exclusive['uuid'])->value('prices_include_tax'));
    }

    public function test_qr_checkout_prices_vat_inside_the_menu_price_and_stamps_the_order(): void
    {
        $this->registerVat(100, true);
        $this->p4Tax();
        $product = $this->p4Product('Shawarma', '2.000');
        $session = $this->p4QrSession();
        $lines = [['product_id' => $product, 'qty' => 2, 'addon_ids' => [], 'notes' => '']];

        $this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()
            ->assertJsonPath('data.tax', ['vat_registered' => true, 'prices_include_vat' => true, 'prices_include_tax' => true]);
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => $lines])->assertOk()
            ->assertJsonPath('data.quote.subtotal_baisas', 4000)
            ->assertJsonPath('data.quote.tax_total_baisas', 190)
            ->assertJsonPath('data.quote.grand_total_baisas', 4000)
            ->assertJsonPath('data.quote.prices_include_tax', true);

        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout($lines))->assertStatus(201)
            ->assertJsonPath('data.order.tax_total_baisas', 190)
            ->assertJsonPath('data.order.grand_total_baisas', 4000);
        $order = DB::table('pos_orders')->sole();
        $this->assertTrue((bool) $order->prices_include_tax);
        $this->assertSame([4000, 190, 4000], [Money::toBaisas($order->subtotal), Money::toBaisas($order->tax_total), Money::toBaisas($order->grand_total)]);
    }

    public function test_the_bill_header_keeps_an_inclusive_bill_tax_inside_its_total_after_a_discount(): void
    {
        $totals = app(RefreshQrOrderTotalsAction::class);

        // Inclusive: 4.000 with 0.190 VAT inside, a 0.400 manual discount.
        $inclusive = $totals->header(['subtotal' => 4000, 'tax' => 190, 'total' => 4000, 'manual' => 400, 'comp' => 0, 'loyalty' => 0, 'inclusive' => 1]);
        $this->assertSame(['4.000', '0.400', '0.000', '0.171', '3.600'], array_values(array_map('strval', $inclusive)));
        $this->assertSame(4000, RefreshQrOrderTotalsAction::net(['total' => 4000, 'tax' => 190, 'inclusive' => 1]));

        // Exclusive bills are unchanged: the tax stays on top.
        $exclusive = $totals->header(['subtotal' => 4000, 'tax' => 200, 'total' => 4200, 'manual' => 400, 'comp' => 0, 'loyalty' => 0]);
        $this->assertSame(['4.000', '0.400', '0.000', '0.180', '3.780'], array_values(array_map('strval', $exclusive)));
    }
}
