<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P-F6 — GET /api/v1/device/reports/branch: the device's full-screen branch
 * Reports dashboard. Pure aggregation over the device's company+branch PAID
 * orders inside [from 00:00, to 23:59] — voided/refunded excluded, money in
 * integer baisas, envelope {data: {report}, meta, errors}.
 *
 * Seeds two companies / two branches with paid + voided orders, split
 * tenders (cash + bank_pos), rule + manual discounts sharing a name, a
 * reasoned comp + a gift comp, loyalty earn + redeem, and consumption
 * movements — then asserts every section's exact numbers plus scoping,
 * window filtering, defensive from/to fallback and the auth guards.
 * Regressions here are silent (numbers just drift), so keep it exact.
 */
class DeviceBranchReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/device/reports/branch';

    private const WINDOW = '?from=2026-03-02&to=2026-03-08';

    private function device(string $token = 'mdev_rpt', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'order_type' => 'dine_in',
            'status' => Order::STATUS_PAID,
            'source' => 'main_pos',
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '0.000',
            'opened_at' => '2026-03-02 09:15:00',
        ], $overrides));
    }

    private function payment(int $orderId, string $method, string $amount): void
    {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'method' => $method,
            'amount' => $amount,
            'status' => 'success',
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function item(int $orderId, string $name, string $qty, string $lineTotal): void
    {
        DB::table('pos_order_items')->insert([
            'order_id' => $orderId,
            'product_id' => null,
            'product_name_snapshot' => $name,
            'qty' => $qty,
            'unit_price_snapshot' => '1.000',
            'line_discount' => '0.000',
            'line_total' => $lineTotal,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function discount(int $orderId, string $name, string $amount, ?int $discountId = null): void
    {
        DB::table('pos_order_discounts')->insert([
            'company_id' => 100,
            'branch_id' => 10,
            'order_id' => $orderId,
            'discount_id' => $discountId,
            'name_snapshot' => $name,
            'amount' => $amount,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function comp(int $orderId, string $amount, bool $isGift, int $branchId = 10): void
    {
        DB::table('pos_order_comps')->insert([
            'company_id' => 100,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'reason_code_snapshot' => $isGift ? 'gift' : 'quality',
            'reason_name_snapshot' => $isGift ? 'Gift' : 'Quality Issue',
            'is_gift' => $isGift,
            'amount' => $amount,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loyalty(int $accountId, string $type, int $points, int $stamps, ?int $orderId): void
    {
        DB::table('pos_loyalty_transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'loyalty_account_id' => $accountId,
            'type' => $type,
            'points_delta' => $points,
            'stamps_delta' => $stamps,
            'balance_after_points' => 0,
            'balance_after_stamps' => 0,
            'order_id' => $orderId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function movement(int $ingredientId, string $type, string $qty, string $occurredAt, int $branchId = 10): void
    {
        DB::table('pos_stock_movements')->insert([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredientId,
            'movement_type' => $type,
            'quantity' => $qty,
            'unit_cost_at_time' => '0.000',
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);
    }

    /**
     * The full fixture: 4 in-window paid orders at the device's branch plus
     * deliberate noise (voided/refunded/open, other branch, other company,
     * outside the window) that must never surface.
     *
     * @return array<string, Order>
     */
    private function seedReportData(): array
    {
        DB::table('pos_customers')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Ali', 'phone' => '90000001', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Sara', 'phone' => '90000002', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'OtherBranchGuy', 'phone' => '90000003', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ---- The window's PAID orders (branch 10) ----
        $o1 = $this->order(['opened_at' => '2026-03-02 09:15:00', 'order_type' => 'dine_in', 'customer_id' => 1, 'grand_total' => '2.500', 'discount_total' => '0.500', 'tax_total' => '0.125']);
        $o2 = $this->order(['opened_at' => '2026-03-02 09:45:00', 'order_type' => 'to_go', 'customer_id' => 1, 'grand_total' => '1.500', 'discount_total' => '0.000', 'tax_total' => '0.075']);
        $o3 = $this->order(['opened_at' => '2026-03-05 14:05:00', 'order_type' => 'dine_in', 'customer_id' => 2, 'grand_total' => '4.500', 'discount_total' => '0.250', 'tax_total' => '0.200']);
        $o4 = $this->order(['opened_at' => '2026-03-08 23:30:00', 'order_type' => 'delivery', 'customer_id' => null, 'grand_total' => '2.000', 'discount_total' => '0.000', 'tax_total' => '0.100']);

        // ---- Noise that must NEVER surface ----
        $void = $this->order(['opened_at' => '2026-03-03 10:00:00', 'status' => Order::STATUS_VOID, 'customer_id' => 1, 'grand_total' => '9.999']);
        $this->order(['opened_at' => '2026-03-04 11:00:00', 'status' => Order::STATUS_REFUNDED, 'grand_total' => '5.000']);
        $this->order(['opened_at' => '2026-03-04 12:00:00', 'status' => Order::STATUS_OPEN, 'grand_total' => '3.333']);
        $otherBranch = $this->order(['opened_at' => '2026-03-03 13:00:00', 'branch_id' => 11, 'customer_id' => 3, 'grand_total' => '7.777']);
        $this->order(['opened_at' => '2026-03-03 13:00:00', 'company_id' => 200, 'branch_id' => 20, 'grand_total' => '8.888']);
        $outside = $this->order(['opened_at' => '2026-02-20 10:00:00', 'grand_total' => '6.000']);
        $this->order(['opened_at' => '2026-03-09 00:30:00', 'grand_total' => '6.500']); // just past the window

        // ---- Tenders (cash + bank_pos, incl. a split) ----
        $this->payment($o1->id, 'cash', '2.000');
        $this->payment($o1->id, 'bank_pos', '0.500');
        $this->payment($o2->id, 'cash', '1.500');
        $this->payment($o3->id, 'bank_pos', '4.500');
        $this->payment($o4->id, 'cash', '2.000');
        $this->payment($void->id, 'cash', '9.999'); // voided order — excluded

        // ---- Items (Tea qty is decimal on purpose) ----
        $this->item($o1->id, 'Latte', '2.000', '3.000');
        $this->item($o2->id, 'Tea', '1.500', '1.200');
        $this->item($o3->id, 'Latte', '1.000', '1.500');
        $this->item($o3->id, 'Cake', '1.000', '2.500');
        $this->item($void->id, 'VoidItem', '1.000', '9.999'); // excluded

        // ---- Discounts: a rule + a manual application sharing one name ----
        $this->discount($o1->id, 'Happy Hour', '0.300', 5);
        $this->discount($o3->id, 'Happy Hour', '0.250', null);
        $this->discount($o2->id, 'Staff Manual', '0.200', null);
        $this->discount($void->id, 'VoidDisc', '9.000', null); // excluded

        // ---- Comps: one reasoned + one gift (P-F5 is_gift) ----
        $this->comp($o1->id, '0.400', false);
        $this->comp($o3->id, '1.000', true);
        $this->comp($otherBranch->id, '5.000', false, 11); // excluded with its order

        // ---- Loyalty: earn + redeem on window orders, noise elsewhere ----
        DB::table('pos_loyalty_accounts')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => 1, 'loyalty_rule_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => 2, 'loyalty_rule_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->loyalty(1, 'earn', 10, 1, $o1->id);
        $this->loyalty(2, 'earn', 5, 0, $o3->id);
        $this->loyalty(1, 'redeem', -4, -1, $o2->id);
        $this->loyalty(1, 'earn', 100, 9, $outside->id);      // outside window — excluded
        $this->loyalty(1, 'earn', 50, 5, $otherBranch->id);   // other branch — excluded
        $this->loyalty(1, 'adjust', 7, 0, $o1->id);           // not earn/redeem — excluded

        // ---- Stock consumption ledger ----
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'L', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Beans', 'unit' => 'kg', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->movement(1, 'sale_consumption', '-2.500', '2026-03-02 09:20:00');
        $this->movement(1, 'addon_consumption', '-0.500', '2026-03-05 14:10:00');
        $this->movement(2, 'sale_consumption', '-0.250', '2026-03-02 09:20:00');
        // A paid-then-voided sale: consumption + its reversal net to zero.
        $this->movement(2, 'sale_consumption', '-1.000', '2026-03-03 10:00:00');
        $this->movement(2, 'sale_consumption', '1.000', '2026-03-03 10:05:00');
        $this->movement(1, 'restock', '10.000', '2026-03-03 08:00:00');               // wrong type — excluded
        $this->movement(1, 'sale_consumption', '-9.000', '2026-03-03 09:00:00', 11);  // other branch — excluded
        $this->movement(1, 'sale_consumption', '-5.000', '2026-02-01 09:00:00');      // outside window — excluded

        return ['o1' => $o1, 'o2' => $o2, 'o3' => $o3, 'o4' => $o4];
    }

    // ============================ sections ============================

    public function test_envelope_and_window_echo(): void
    {
        $this->device();
        $this->seedReportData();

        $res = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)->assertOk();

        $res->assertJsonPath('meta.money_unit', 'baisas');
        $this->assertSame([], $res->json('errors'));
        $report = $res->json('data.report');
        $this->assertSame('2026-03-02', $report['from']);
        $this->assertSame('2026-03-08', $report['to']);
    }

    public function test_summary_numbers(): void
    {
        $this->device();
        $this->seedReportData();

        $s = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.summary');

        $this->assertSame(4, $s['orders']);
        $this->assertSame(10500, $s['gross_baisas']);          // 2.5 + 1.5 + 4.5 + 2.0
        $this->assertSame(750, $s['discount_baisas']);          // 0.5 + 0.25
        $this->assertSame(400, $s['comp_baisas']);              // reasoned comp only
        $this->assertSame(1000, $s['gift_baisas']);             // is_gift comp only
        $this->assertSame(500, $s['tax_baisas']);               // 0.125+0.075+0.2+0.1
        $this->assertSame(2625, $s['avg_order_baisas']);        // 10500 / 4
        $this->assertSame(2, $s['distinct_customers']);         // Ali + Sara (null ignored)
        $this->assertSame(15, $s['loyalty_points_earned']);     // 10 + 5
        $this->assertSame(4, $s['loyalty_points_redeemed']);    // |-4|
        $this->assertSame(1, $s['loyalty_stamps_earned']);
        $this->assertSame(1, $s['loyalty_stamps_redeemed']);
    }

    public function test_by_day_is_sparse_and_ascending(): void
    {
        $this->device();
        $this->seedReportData();

        $byDay = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.by_day');

        $this->assertSame([
            ['date' => '2026-03-02', 'total_baisas' => 4000, 'orders' => 2],
            ['date' => '2026-03-05', 'total_baisas' => 4500, 'orders' => 1],
            ['date' => '2026-03-08', 'total_baisas' => 2000, 'orders' => 1],
        ], $byDay);
    }

    public function test_by_hour_is_sparse_and_ascending(): void
    {
        $this->device();
        $this->seedReportData();

        $byHour = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.by_hour');

        $this->assertSame([
            ['hour' => 9, 'total_baisas' => 4000, 'orders' => 2],
            ['hour' => 14, 'total_baisas' => 4500, 'orders' => 1],
            ['hour' => 23, 'total_baisas' => 2000, 'orders' => 1],
        ], $byHour);
    }

    public function test_by_method_aggregates_raw_payment_methods(): void
    {
        $this->device();
        $this->seedReportData();

        $byMethod = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.by_method');

        // Value-descending; the voided order's 9.999 cash row never counts.
        $this->assertSame([
            ['method' => 'cash', 'total_baisas' => 5500, 'count' => 3],
            ['method' => 'bank_pos', 'total_baisas' => 5000, 'count' => 2],
        ], $byMethod);
    }

    public function test_by_order_type(): void
    {
        $this->device();
        $this->seedReportData();

        $byType = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.by_order_type');

        $this->assertSame([
            ['order_type' => 'dine_in', 'total_baisas' => 7000, 'count' => 2],
            ['order_type' => 'delivery', 'total_baisas' => 2000, 'count' => 1],
            ['order_type' => 'to_go', 'total_baisas' => 1500, 'count' => 1],
        ], $byType);
    }

    public function test_top_products_by_value_with_decimal_qty(): void
    {
        $this->device();
        $this->seedReportData();

        $top = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.top_products');

        $this->assertCount(3, $top);
        $this->assertSame('Latte', $top[0]['name']);
        $this->assertSame(4500, $top[0]['total_baisas']);
        $this->assertEquals(3.0, $top[0]['qty']);
        $this->assertSame('Cake', $top[1]['name']);
        $this->assertSame(2500, $top[1]['total_baisas']);
        $this->assertSame('Tea', $top[2]['name']);
        $this->assertSame(1200, $top[2]['total_baisas']);
        $this->assertEquals(1.5, $top[2]['qty']); // decimal qty emitted as a number
    }

    public function test_top_customers_by_value_with_names(): void
    {
        $this->device();
        $this->seedReportData();

        $top = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.top_customers');

        // Sara 4.500 > Ali 2.500+1.500; the customer-less order 4 is absent;
        // OtherBranchGuy's order sits on branch 11 and never leaks in.
        $this->assertSame([
            ['name' => 'Sara', 'orders' => 1, 'total_baisas' => 4500],
            ['name' => 'Ali', 'orders' => 2, 'total_baisas' => 4000],
        ], $top);
    }

    public function test_discounts_group_rule_and_manual_by_name(): void
    {
        $this->device();
        $this->seedReportData();

        $discounts = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.discounts');

        $this->assertSame([
            ['name' => 'Happy Hour', 'amount_baisas' => 550, 'count' => 2], // rule + manual together
            ['name' => 'Staff Manual', 'amount_baisas' => 200, 'count' => 1],
        ], $discounts);
    }

    public function test_stock_consumption_nets_reversals_and_emits_positive_qty(): void
    {
        $this->device();
        $this->seedReportData();

        $consumption = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report.stock_consumption');

        // Milk: 2.5 sale + 0.5 addon = 3.0; Beans: 0.25 (the voided sale's
        // -1.0/+1.0 pair nets out). Restock, other-branch and out-of-window
        // rows never count. Positive quantities, biggest first.
        $this->assertCount(2, $consumption);
        $this->assertSame('Milk', $consumption[0]['name']);
        $this->assertSame('L', $consumption[0]['unit']);
        $this->assertEquals(3.0, $consumption[0]['qty']);
        $this->assertSame('Beans', $consumption[1]['name']);
        $this->assertSame('kg', $consumption[1]['unit']);
        $this->assertEquals(0.25, $consumption[1]['qty']);
    }

    // ===================== scoping + empty state =====================

    public function test_other_branch_and_other_company_data_never_leak(): void
    {
        $this->device(); // branch 10
        $this->seedReportData();

        $report = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report');

        // Gross excludes branch 11's 7.777 and company 200's 8.888 — checked
        // exactly in test_summary_numbers; spot-check the join sections too.
        $this->assertSame(10500, $report['summary']['gross_baisas']);
        $names = array_column($report['top_customers'], 'name');
        $this->assertNotContains('OtherBranchGuy', $names);
    }

    public function test_a_device_on_the_other_branch_sees_only_its_own_slice(): void
    {
        $this->seedReportData();
        $this->device('mdev_rpt_b11', 100, 11);

        $report = $this->withToken('mdev_rpt_b11')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report');

        $this->assertSame(1, $report['summary']['orders']);
        $this->assertSame(7777, $report['summary']['gross_baisas']);
        $this->assertSame([], $report['discounts']);
        // Branch 11's only consumption row is the 9.0 Milk movement.
        $this->assertEquals(9.0, $report['stock_consumption'][0]['qty']);
    }

    public function test_empty_window_emits_every_key_with_zeros(): void
    {
        $this->device();
        // No data seeded at all.

        $report = $this->withToken('mdev_rpt')->getJson(self::URL.self::WINDOW)
            ->assertOk()->json('data.report');

        $this->assertSame([
            'orders' => 0, 'gross_baisas' => 0, 'discount_baisas' => 0,
            'comp_baisas' => 0, 'gift_baisas' => 0, 'tax_baisas' => 0,
            'avg_order_baisas' => 0, 'distinct_customers' => 0,
            'loyalty_points_earned' => 0, 'loyalty_points_redeemed' => 0,
            'loyalty_stamps_earned' => 0, 'loyalty_stamps_redeemed' => 0,
        ], $report['summary']);
        foreach (['by_day', 'by_hour', 'by_method', 'by_order_type', 'top_products', 'top_customers', 'discounts', 'stock_consumption'] as $section) {
            $this->assertSame([], $report[$section], "section $section should be an empty list");
        }
    }

    // ===================== window resolution =====================

    public function test_defaults_to_the_last_seven_days_when_no_params(): void
    {
        $this->device();

        $report = $this->withToken('mdev_rpt')->getJson(self::URL)->assertOk()->json('data.report');

        $this->assertSame(today()->toDateString(), $report['to']);
        $this->assertSame(today()->subDays(6)->toDateString(), $report['from']);
    }

    public function test_garbage_dates_fall_back_to_the_last_seven_days(): void
    {
        $this->device();

        foreach (['?from=banana&to=2026-99-99', '?from=2026-02-30&to=', '?from[]=1&to[]=2'] as $qs) {
            $report = $this->withToken('mdev_rpt')->getJson(self::URL.$qs)->assertOk()->json('data.report');
            $this->assertSame(today()->toDateString(), $report['to'], "qs: $qs");
            $this->assertSame(today()->subDays(6)->toDateString(), $report['from'], "qs: $qs");
        }
    }

    public function test_an_inverted_window_falls_back_to_seven_days_ending_at_to(): void
    {
        $this->device();

        $report = $this->withToken('mdev_rpt')
            ->getJson(self::URL.'?from=2026-03-08&to=2026-03-02')->assertOk()->json('data.report');

        $this->assertSame('2026-03-02', $report['to']);
        $this->assertSame('2026-02-24', $report['from']); // to − 6 days
    }

    public function test_the_span_is_capped_at_92_days(): void
    {
        $this->device();

        $report = $this->withToken('mdev_rpt')
            ->getJson(self::URL.'?from=2025-01-01&to=2026-03-08')->assertOk()->json('data.report');

        $this->assertSame('2026-03-08', $report['to']);
        $this->assertSame(
            Carbon::parse('2026-03-08')->subDays(91)->toDateString(),
            $report['from'],
        );
    }

    public function test_window_filter_excludes_orders_outside_it(): void
    {
        $this->device();
        $this->seedReportData();

        // A one-day window around 2026-03-05 sees ONLY order 3.
        $report = $this->withToken('mdev_rpt')
            ->getJson(self::URL.'?from=2026-03-05&to=2026-03-05')->assertOk()->json('data.report');

        $this->assertSame(1, $report['summary']['orders']);
        $this->assertSame(4500, $report['summary']['gross_baisas']);
        $this->assertSame([['date' => '2026-03-05', 'total_baisas' => 4500, 'orders' => 1]], $report['by_day']);
    }

    // ========================== guards ==========================

    public function test_requires_a_device_token(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected_with_409(): void
    {
        Device::factory()->paired('mdev_rpt_unassigned')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_rpt_unassigned')->getJson(self::URL)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'device_unassigned');
    }
}
