<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class DeviceSyncCommissionTest extends TestCase
{
    use RefreshDatabase;

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
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, int $grandBaisas = 3000): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subHours(3)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subHours(3)->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => $grandBaisas, 'change_given_baisas' => 0]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_pay_records_the_per_party_commission_breakdown(): void
    {
        $this->seedProduct();
        $this->device();
        $profileId = $this->seedCommission([
            ['party_type' => 'platform', 'label' => 'Platform', 'percent' => 2],
            ['party_type' => 'bank', 'label' => 'Acme Bank', 'percent' => 3],
        ]);

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $res = $this->push('mdev_com', [$this->payEvent($uuid)])->assertOk();

        $this->assertSame('paid', $res->json('data.results.0.result.status'));
        $this->assertCount(3, $res->json('data.results.0.result.sale_commission_ids'));

        $order = Order::firstWhere('uuid', $uuid);
        $rows = DB::table('pos_sale_commissions')->where('order_id', $order->id)->orderBy('sort_order')->get();

        $this->assertCount(3, $rows);

        // Platform 2% of 3.000 = 0.060.
        $this->assertSame('platform', $rows[0]->party_type);
        $this->assertSame('Platform', $rows[0]->party_label);
        $this->assertEqualsWithDelta(0.060, (float) $rows[0]->commission_amount, 1e-9);

        // Bank 3% = 0.090.
        $this->assertSame('bank', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.090, (float) $rows[1]->commission_amount, 1e-9);

        // Merchant residual = 3.000 - 0.150 = 2.850 (95%).
        $this->assertSame('merchant', $rows[2]->party_type);
        $this->assertEqualsWithDelta(95.0, (float) $rows[2]->percent, 1e-9);
        $this->assertEqualsWithDelta(2.850, (float) $rows[2]->commission_amount, 1e-9);

        // Snapshots: profile id + gross on every row; rows sum to grand_total.
        foreach ($rows as $row) {
            $this->assertSame($profileId, (int) $row->commission_profile_id);
            $this->assertEqualsWithDelta(3.0, (float) $row->gross_amount, 1e-9);
        }
        $this->assertEqualsWithDelta(3.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }

    public function test_no_profile_records_no_breakdown(): void
    {
        $this->seedProduct();
        $this->device();

        $uuid = (string) Str::uuid();
        $this->push('mdev_com', [$this->createEvent($uuid)])->assertOk();
        $this->push('mdev_com', [$this->payEvent($uuid)])->assertOk();

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
        $this->push('mdev_com', [$this->payEvent($uuid)])->assertOk();

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
        $pay = $this->payEvent($uuid);

        $this->push('mdev_com', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_sale_commissions', 3);

        // The device re-pushes the identical batch — nothing splits twice.
        $this->push('mdev_com', [$create, $pay])->assertOk();
        $this->assertDatabaseCount('pos_sale_commissions', 3);
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
        $this->push('mdev_com', [$this->payEvent($uuid, 1000)])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $rows = DB::table('pos_sale_commissions')->where('order_id', $order->id)->orderBy('sort_order')->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.333, (float) $rows[0]->commission_amount, 1e-9);
        $this->assertSame('merchant', $rows[1]->party_type);
        $this->assertEqualsWithDelta(0.667, (float) $rows[1]->commission_amount, 1e-9);
        $this->assertEqualsWithDelta(1.0, $rows->sum(fn ($r): float => (float) $r->commission_amount), 1e-9);
    }
}
