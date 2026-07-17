<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Per-sale commission breakdown (pos_sale_commissions).
 *
 * At order.pay the merchant's commission profile is applied to the sale's
 * grand_total: one row per configured share line (platform / bank / other)
 * plus the merchant residual. Each non-merchant party is rounded in baisas
 * and the merchant takes the exact remainder, so the rows always sum to
 * grand_total. No active profile ⇒ no rows (merchant keeps 100%).
 *
 * Payment-method scoping: a BANK line is an acquirer fee, charged only on
 * the card-paid portion of the sale; platform/other lines apply to the
 * whole total regardless of tender.
 */
class DeviceSyncCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the commission order's cashier (staff 7) is in the tenant.
        $this->seedPosStaff([7]);
    }

    private function device(string $token = 'mdev_com', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /** A single recipe-less, untracked product so PAY touches no inventory. */
    private function seedProduct(int $company = 100): void
    {
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * @param  list<array{party_type: string, label: string, percent: float|int}>  $shares
     */
    private function seedCommission(array $shares, int $company = 100, bool $active = true): int
    {
        $merchant = 100 - array_sum(array_map(static fn (array $s): float => (float) $s['percent'], $shares));

        $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company,
            'is_active' => $active,
            'merchant_percent' => $merchant,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sort = 0;
        foreach ($shares as $share) {
            DB::table('pos_commission_shares')->insert([
                'commission_profile_id' => $profileId,
                'party_type' => $share['party_type'],
                'label' => $share['label'],
                'percent' => $share['percent'],
                // Channel scope; defaults to 'all' (the pre-channel behaviour).
                'applies_to' => $share['applies_to'] ?? 'all',
                'sort_order' => $sort++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $profileId;
    }

    /**
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid, int $grandBaisas = 3000): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHours(4)->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->subHours(4)->toIso8601String(),
                'subtotal_baisas' => $grandBaisas,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => $grandBaisas,
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 1,
                    'unit_price_baisas' => $grandBaisas,
                    'line_discount_baisas' => 0,
                    'line_total_baisas' => $grandBaisas,
                ]],
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tenders
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, array $tenders): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subHours(3)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subHours(3)->toIso8601String(),
                'payments' => $tenders,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cash(int $baisas): array
    {
        return [['method' => 'cash', 'amount_baisas' => $baisas, 'change_given_baisas' => 0]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function card(int $baisas): array
    {
        return [['method' => 'card', 'amount_baisas' => $baisas, 'softpos_reference' => 'TXN1', 'softpos_auth_code' => 'A1']];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    /**
     * @return Collection<int, object>
     */
    private function breakdownFor(string $uuid): Collection
    {
        $order = Order::firstWhere('uuid', $uuid);

        return DB::table('pos_sale_commissions')->where('order_id', $order->id)->orderBy('sort_order')->get();
    }

    public function test_pay_records_the_per_party_commission_breakdown_on_a_card_sale(): void
    {
        $this->seedProduct();
        $this->device();
        $profileId = $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid, $this->card(3000))])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertCount(3, $res->json('data.results.0.result.sale_commission_ids'));

        $rows = $this->breakdownFor($uuid);
        $this->assertCount(3, $rows);

        // Platform 2% of 3.000 = 0.060 (on the whole sale).
        $this->assertSame('platform', $rows[0]->party_type);
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);

        // Bank 3% of the 3.000 card-paid amount = 0.090.
        $this->assertSame('bank', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.090, (float) $rows[1]->commission_amount, 1e-9);

        // Merchant residual = 3.000 - 0.150 = 2.850 (95%).
        $this->assertSame('merchant', $rows[2]->party_type);
        $this->assertEqualsWithDelta(95.0, (float) $rows[2]->percent, 1e-9);
        $this->assertEqualsWithDelta(2.850, (float) $rows[2]->commission_amount, 1e-9);

        foreach ($rows as $row) {
            $this->assertSame($profileId, (int) $row->commission_profile_id);
            $this->assertEqualsWithDelta(3.0, (float) $row->gross_amount, 1e-9);
        }
        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    public function test_bank_cut_is_skipped_on_a_cash_sale(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, $this->cash(3000))])->assertOk();

        $rows = $this->breakdownFor($uuid);
        $this->assertCount(3, $rows);

        // Platform still 0.060 (applies to all sales); bank 0.000 (no card).
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertSame('bank', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]->commission_amount, 1e-9);
        // Merchant keeps the bank's would-be slice: 3.000 - 0.060 = 2.940.
        $this->assertSame('merchant', $rows[2]->party_type);
        $this->assertEqualsWithDelta(2.940, (float) $rows[2]->commission_amount, 1e-9);

        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    public function test_bank_cut_applies_only_to_the_card_portion_of_a_split(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        // Split: 2.000 cash + 1.000 card on a 3.000 sale.
        $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'cash', 'amount_baisas' => 2000, 'change_given_baisas' => 0],
            ['method' => 'card', 'amount_baisas' => 1000, 'softpos_reference' => 'TXN1'],
        ])])->assertOk();

        $rows = $this->breakdownFor($uuid);

        // Platform 2% of 3.000 = 0.060; bank 3% of the 1.000 card part = 0.030.
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(0.030, (float) $rows[1]->commission_amount, 1e-9);
        // Merchant = 3.000 - 0.060 - 0.030 = 2.910.
        $this->assertEqualsWithDelta(2.910, (float) $rows[2]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    /**
     * Phase D4 (blueprint §6.8 Gift — "zero charged to customer"): a fully
     * gifted sale collects nothing, so even with an active profile NO
     * commission rows are recorded — there is nothing to share.
     */
    public function test_a_fully_gifted_sale_records_no_breakdown(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'gift', 'amount_baisas' => 3000],
        ])])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertSame([], $res->json('data.results.0.result.sale_commission_ids'));
        $this->assertCount(0, $this->breakdownFor($uuid));
    }

    /**
     * Phase D4 — a PARTIAL gift (split card + gift): the bank cut applies to
     * the card portion only, platform to the COLLECTED amount (gross − gift),
     * and the rows sum to collected, not grand_total.
     */
    public function test_gifted_portion_is_excluded_from_the_commission_base(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        // 1.000 card + 2.000 gifted on a 3.000 sale → collected = 1.000.
        $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 1000, 'softpos_reference' => 'TXN1'],
            ['method' => 'gift', 'amount_baisas' => 2000],
        ])])->assertOk();

        $rows = $this->breakdownFor($uuid);
        $this->assertCount(3, $rows);

        // Platform 2% of the 1.000 collected = 0.020; bank 3% of the 1.000
        // card part = 0.030; merchant remainder = 1.000 − 0.050 = 0.950.
        $this->assertEqualsWithDelta(0.020, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(0.030, (float) $rows[1]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(0.950, (float) $rows[2]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(1.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
        // gross_amount still snapshots the full sale.
        $this->assertEqualsWithDelta(3.0, (float) $rows[0]->gross_amount, 1e-9);
    }

    /**
     * P-F5 — bank_pos (paid on the bank's own standalone terminal) is NOT
     * card money: the bank already took its acquirer fee on its own rails,
     * so the bank-party slice stays 0 and the merchant keeps it — exactly
     * the cash-only outcome.
     */
    public function test_bank_pos_tender_carries_no_bank_cut_like_cash(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'bank_pos', 'amount_baisas' => 3000],
        ])])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertDatabaseHas('pos_payments', ['method' => 'bank_pos', 'amount' => '3.000', 'status' => 'success']);

        $rows = $this->breakdownFor($uuid);
        $this->assertCount(3, $rows);

        // Identical to the cash-only split: platform 0.060, bank 0.000,
        // merchant keeps the bank's would-be slice (2.940).
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertSame('bank', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]->commission_amount, 1e-9);
        $this->assertSame('merchant', $rows[2]->party_type);
        $this->assertEqualsWithDelta(2.940, (float) $rows[2]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    /** P-F5 — a mixed cash + bank_pos pay: both flow like cash, sum == grand. */
    public function test_mixed_cash_and_bank_pos_split_pays_and_skips_the_bank_cut(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'cash', 'amount_baisas' => 2000, 'change_given_baisas' => 0],
            ['method' => 'bank_pos', 'amount_baisas' => 1000],
        ])])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertCount(2, $res->json('data.results.0.result.payment_ids'));
        $this->assertDatabaseHas('pos_payments', ['method' => 'bank_pos', 'amount' => '1.000']);

        $rows = $this->breakdownFor($uuid);

        // No card tender anywhere → bank 0.000; platform 2% of 3.000.
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(2.940, (float) $rows[2]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    public function test_channel_scoped_lines_bite_only_their_channel(): void
    {
        $this->seedProduct();
        $this->device();
        // Per-channel platform commission: 1% on card sales, 2% on cash/bank-POS.
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Mithqal card', 'percent' => 1, 'applies_to' => 'card'],
            ['party_type' => 'platform', 'label' => 'Mithqal cash', 'percent' => 2, 'applies_to' => 'cash_bank'],
        ]);

        // CARD sale 10.000 → card line 1% = 100; cash line base 0 → 0; merchant 9900.
        $cardUuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($cardUuid, 10000)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($cardUuid, $this->card(10000))])->assertOk();
        // number_format: raw SQLite reads drop trailing zeros ('0.100' → '0.1').
        $cardRows = $this->breakdownFor($cardUuid)->keyBy('party_label');
        $this->assertSame('0.100', number_format((float) $cardRows['Mithqal card']->commission_amount, 3, '.', ''));
        $this->assertSame('0.000', number_format((float) $cardRows['Mithqal cash']->commission_amount, 3, '.', ''));
        $this->assertSame('9.900', number_format((float) $cardRows['Merchant']->commission_amount, 3, '.', ''));

        // CASH sale 10.000 → card line base 0 → 0; cash line 2% = 200; merchant 9800.
        $cashUuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($cashUuid, 10000)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($cashUuid, $this->cash(10000))])->assertOk();
        $cashRows = $this->breakdownFor($cashUuid)->keyBy('party_label');
        $this->assertSame('0.000', number_format((float) $cashRows['Mithqal card']->commission_amount, 3, '.', ''));
        $this->assertSame('0.200', number_format((float) $cashRows['Mithqal cash']->commission_amount, 3, '.', ''));
        $this->assertSame('9.800', number_format((float) $cashRows['Merchant']->commission_amount, 3, '.', ''));
    }

    public function test_channel_scoped_lines_split_a_mixed_tender_by_portion(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Mithqal card', 'percent' => 1, 'applies_to' => 'card'],
            ['party_type' => 'platform', 'label' => 'Mithqal cash', 'percent' => 2, 'applies_to' => 'cash_bank'],
        ]);

        // Mixed 6.000 card + 4.000 cash → card line 1% of 6000 = 60; cash line
        // 2% of (10000−6000) = 80; merchant takes the exact remainder 9860.
        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid, 10000)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 6000, 'softpos_reference' => 'TXN9', 'softpos_auth_code' => 'A9'],
            ['method' => 'cash', 'amount_baisas' => 4000, 'change_given_baisas' => 0],
        ])])->assertOk();

        $rows = $this->breakdownFor($uuid)->keyBy('party_label');
        $this->assertSame('0.060', number_format((float) $rows['Mithqal card']->commission_amount, 3, '.', ''));
        $this->assertSame('0.080', number_format((float) $rows['Mithqal cash']->commission_amount, 3, '.', ''));
        $this->assertSame('9.860', number_format((float) $rows['Merchant']->commission_amount, 3, '.', ''));
        // Σ(rows) == collected, to the baisa.
        $this->assertSame('10.000', number_format((float) $this->breakdownFor($uuid)->sum('commission_amount'), 3, '.', ''));
    }

    public function test_no_profile_records_no_breakdown(): void
    {
        $this->seedProduct();
        $this->device();

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, $this->card(3000))])->assertOk();

        $this->assertSame('paid', Order::firstWhere('uuid', $uuid)->status);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
    }

    public function test_inactive_profile_records_no_breakdown(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 5],
        ], active: false);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, $this->card(3000))])->assertOk();

        $this->assertDatabaseCount('pos_sale_commissions', 0);
    }

    public function test_breakdown_is_idempotent_on_replay(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $create = $this->createEvent($uuid);
        $pay = $this->payEvent($uuid, $this->card(3000));

        $this->push('mdev_com', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_sale_commissions', 3);

        // The device re-pushes the identical batch — nothing splits twice.
        $this->push('mdev_com', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_sale_commissions', 3);
    }

    /**
     * P-F7 — a force-recorded (ambiguous) card tender arrives
     * pending_reconciliation: the money is NOT confirmed, so the commission
     * split is DEFERRED — no pos_sale_commissions rows at pay time. The
     * pos_admin approval queue records the split once the bank file
     * confirms the money (ApprovePendingReconciliationAction).
     */
    public function test_a_pending_reconciliation_tender_defers_the_commission_split(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'card', 'amount_baisas' => 3000, 'status' => 'pending_reconciliation', 'softpos_reference' => 'NFC-T1'],
        ])])->assertOk();

        // The order still pays (goods left the shop) but records NO split.
        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertSame([], $res->json('data.results.0.result.sale_commission_ids'));
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseHas('pos_payments', [
            'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
        ]);
    }

    /** P-F7 — ONE pending tender in a split defers the WHOLE order's split. */
    public function test_a_split_with_one_pending_tender_defers_the_whole_commission_split(): void
    {
        $this->seedProduct();
        $this->device();
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, [
            ['method' => 'cash', 'amount_baisas' => 2000, 'change_given_baisas' => 0],
            ['method' => 'card', 'amount_baisas' => 1000, 'status' => 'pending_reconciliation', 'softpos_reference' => 'NFC-T2'],
        ])])->assertOk();

        $this->assertDatabaseCount('pos_sale_commissions', 0);
    }

    public function test_rounding_leaves_the_exact_remainder_to_the_merchant(): void
    {
        $this->seedProduct();
        $this->device();
        // 33.33% of 1.000 OMR = 0.3333 → rounds to 0.333; merchant takes
        // the exact remainder 0.667, so the two rows still sum to 1.000.
        $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 33.33],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid, 1000)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid, $this->card(1000))])->assertOk();

        $rows = $this->breakdownFor($uuid);

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.333, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertSame('merchant', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.667, (float) $rows[1]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(1.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }
}
