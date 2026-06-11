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
 * P-F8 — merchant-defined order numbering.
 *
 *   POST /api/v1/device/orders/next-number — the server-owned atomic
 *   allocator (scope branch/company per the `order_numbering` company
 *   setting, optional daily reset). 409 numbering_disabled when the
 *   merchant hasn't enabled the policy; 409 device_unassigned like every
 *   sibling /device route.
 *
 * Plus the receipt_number ride-along: order.create accepts an optional
 * receipt_number (<=24, trimmed) onto pos_orders, and
 * GET /device/orders/history emits it.
 */
class DeviceOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/device/orders/next-number';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function device(string $token, int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * One allocation as $token. The pos_device RequestGuard caches the
     * resolved device for the app's lifetime, so a multi-device test MUST
     * flush the guards between requests or every call after the first
     * would run as the FIRST device regardless of token.
     */
    private function allocate(string $token): \Illuminate\Testing\TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken($token)->postJson(self::URL);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setNumbering(int $companyId, array $overrides = []): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => $companyId,
            'key' => 'order_numbering',
            'value' => json_encode(array_merge([
                'enabled' => true,
                'prefix' => 'KLD-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], $overrides)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_allocates_sequential_numbers_formatted_with_prefix_and_pad(): void
    {
        $this->device('mdev_num');
        $this->setNumbering(100);

        $first = $this->allocate('mdev_num')->assertOk()->json();
        $this->assertSame(1, $first['data']['number']);
        $this->assertSame('KLD-0001', $first['data']['formatted']);
        $this->assertSame('branch', $first['meta']['scope']);
        $this->assertNull($first['meta']['seq_date']);
        $this->assertSame([], $first['errors']);

        $second = $this->allocate('mdev_num')->assertOk()->json('data');
        $this->assertSame(2, $second['number']);
        $this->assertSame('KLD-0002', $second['formatted']);
    }

    public function test_sequential_replay_is_strictly_increasing_with_no_duplicates(): void
    {
        $this->device('mdev_num');
        $this->setNumbering(100);

        $numbers = [];
        for ($i = 0; $i < 8; $i++) {
            $numbers[] = (int) $this->allocate('mdev_num')->assertOk()->json('data.number');
        }

        $this->assertSame(range(1, 8), $numbers);
        $this->assertSame($numbers, array_values(array_unique($numbers)));
        // Exactly ONE sequence row was materialised for the scope.
        $this->assertDatabaseCount('pos_order_sequences', 1);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => null, 'next_number' => 9,
        ]);
    }

    public function test_branch_scope_gives_each_branch_its_own_sequence(): void
    {
        $this->device('mdev_num_b10', branch: 10);
        $this->device('mdev_num_b11', branch: 11);
        $this->setNumbering(100, ['scope' => 'branch']);

        $this->assertSame(1, $this->allocate('mdev_num_b10')->json('data.number'));
        $this->assertSame(2, $this->allocate('mdev_num_b10')->json('data.number'));
        // Branch 11 starts at 1 — branch 10's allocations don't bleed over.
        $this->assertSame(1, $this->allocate('mdev_num_b11')->json('data.number'));
        $this->assertSame(3, $this->allocate('mdev_num_b10')->json('data.number'));

        $this->assertDatabaseCount('pos_order_sequences', 2);
    }

    public function test_company_scope_shares_one_sequence_across_branches(): void
    {
        $this->device('mdev_num_b10', branch: 10);
        $this->device('mdev_num_b11', branch: 11);
        $this->setNumbering(100, ['scope' => 'company']);

        $this->assertSame(1, $this->allocate('mdev_num_b10')->json('data.number'));
        $this->assertSame(2, $this->allocate('mdev_num_b11')->json('data.number'));
        $this->assertSame(3, $this->allocate('mdev_num_b10')->json('data.number'));

        // One company-wide row (branch_id NULL).
        $this->assertDatabaseCount('pos_order_sequences', 1);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100, 'branch_id' => null, 'next_number' => 4,
        ]);
    }

    public function test_daily_reset_rolls_a_fresh_row_each_day(): void
    {
        $this->device('mdev_num');
        $this->setNumbering(100, ['daily_reset' => true]);

        Carbon::setTestNow('2026-06-11 21:00:00');
        $this->assertSame(1, $this->allocate('mdev_num')->json('data.number'));
        $this->assertSame(2, $this->allocate('mdev_num')->json('data.number'));
        $this->assertSame('2026-06-11', $this->allocate('mdev_num')->json('meta.seq_date'));

        // Next day: the counter restarts at 1 on a NEW row; yesterday's
        // row is untouched (audit trail of where each day ended).
        Carbon::setTestNow('2026-06-12 08:00:00');
        $next = $this->allocate('mdev_num')->assertOk()->json();
        $this->assertSame(1, $next['data']['number']);
        $this->assertSame('KLD-0001', $next['data']['formatted']);
        $this->assertSame('2026-06-12', $next['meta']['seq_date']);

        $this->assertDatabaseCount('pos_order_sequences', 2);
        $this->assertDatabaseHas('pos_order_sequences', ['seq_date' => '2026-06-11', 'next_number' => 4]);
        $this->assertDatabaseHas('pos_order_sequences', ['seq_date' => '2026-06-12', 'next_number' => 2]);
    }

    public function test_numbering_disabled_is_a_409_backstop(): void
    {
        $this->device('mdev_num');
        // No setting row at all → disabled by default.
        $res = $this->allocate('mdev_num')->assertStatus(409);
        $this->assertSame('numbering_disabled', $res->json('errors.0.code'));

        // An explicit enabled:false row is equally disabled.
        $this->setNumbering(100, ['enabled' => false]);
        $this->allocate('mdev_num')->assertStatus(409);

        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_num_un')->create(['company_id' => null, 'branch_id' => null]);

        $res = $this->allocate('mdev_num_un')->assertStatus(409);
        $this->assertSame('device_unassigned', $res->json('errors.0.code'));
    }

    public function test_requires_a_device_token(): void
    {
        $this->postJson(self::URL)->assertUnauthorized();
    }

    public function test_tenant_isolation_company_b_never_bumps_company_a(): void
    {
        $this->device('mdev_num_a', company: 100, branch: 10);
        $this->device('mdev_num_b', company: 200, branch: 20);
        $this->setNumbering(100, ['scope' => 'company']);
        $this->setNumbering(200, ['scope' => 'company', 'prefix' => 'B-']);

        $this->assertSame(1, $this->allocate('mdev_num_a')->json('data.number'));
        $this->assertSame(2, $this->allocate('mdev_num_a')->json('data.number'));

        // Company B draws from its OWN sequence, starting at 1…
        $b = $this->allocate('mdev_num_b')->assertOk()->json('data');
        $this->assertSame(1, $b['number']);
        $this->assertSame('B-0001', $b['formatted']);

        // …and company A's counter is exactly where A left it.
        $this->assertDatabaseHas('pos_order_sequences', ['company_id' => 100, 'next_number' => 3]);
        $this->assertDatabaseHas('pos_order_sequences', ['company_id' => 200, 'next_number' => 2]);
    }

    // ============ receipt_number on order.create + history ============

    private function seedProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid, array $orderOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $orderUuid,
                'order_type' => 'dine_in',
                'source' => 'main_pos',
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 1500,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 1500,
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 1,
                    'unit_price_baisas' => 1500,
                    'line_discount_baisas' => 0,
                    'line_total_baisas' => 1500,
                ]],
            ], $orderOverrides)],
        ];
    }

    public function test_order_create_persists_the_receipt_number_and_history_emits_it(): void
    {
        $this->device('mdev_num');
        $this->seedProduct();
        $uuid = (string) Str::uuid();

        $this->withToken('mdev_num')->postJson('/api/v1/device/sync/push', [
            'events' => [$this->createEvent($uuid, ['receipt_number' => '  KLD-0042  '])],
        ])->assertOk();

        $order = Order::firstWhere('uuid', $uuid);
        $this->assertSame('KLD-0042', $order->receipt_number); // trimmed

        // The branch history read emits it once the order is terminal.
        $order->update(['status' => Order::STATUS_PAID]);
        $history = $this->withToken('mdev_num')
            ->getJson('/api/v1/device/orders/history')
            ->assertOk()
            ->json('data.orders.0');
        $this->assertSame('KLD-0042', $history['receipt_number']);
    }

    public function test_order_create_without_a_receipt_number_stays_null(): void
    {
        $this->device('mdev_num');
        $this->seedProduct();
        $uuid = (string) Str::uuid();

        // The offline wire contract tolerates an order WITHOUT a number.
        $this->withToken('mdev_num')->postJson('/api/v1/device/sync/push', [
            'events' => [$this->createEvent($uuid)],
        ])->assertOk();

        $this->assertNull(Order::firstWhere('uuid', $uuid)->receipt_number);
    }

    public function test_order_create_rejects_an_over_long_receipt_number(): void
    {
        $this->device('mdev_num');
        $this->seedProduct();
        $uuid = (string) Str::uuid();

        $res = $this->withToken('mdev_num')->postJson('/api/v1/device/sync/push', [
            'events' => [$this->createEvent($uuid, ['receipt_number' => str_repeat('X', 25)])],
        ])->assertOk();

        // The batch envelope is 200; the single event fails validation.
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertNull(Order::firstWhere('uuid', $uuid));
    }
}
