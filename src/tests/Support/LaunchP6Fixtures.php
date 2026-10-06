<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Device;
use App\Models\Order;
use App\Models\TabletOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * LAUNCH-P6 — the customer tablet fixtures: merchant 100 / branch 10 with a
 * tablet, a till and a handheld, staff 7 (cashier), 8 (manager, with an
 * offline verifier) and 9 (supervisor), Coffee 1.000 (5 min) and Cake 2.000
 * (12 min); merchant 200 / branch 20 for the tenancy probes.
 */
trait LaunchP6Fixtures
{
    use LaunchP4Fixtures;
    use LaunchP5Fixtures;
    use TableSessionFixtures;

    protected Device $tablet;

    protected Device $till;

    protected Device $handheld;

    protected int $coffee;

    protected int $cake;

    protected function p6Setup(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->seatingBranch(10, 100);
        $this->tablet = $this->p6Device('mdev_p6_tablet', 'customer_tablet');
        $this->till = $this->p6Device('mdev_p6_till', 'fixed_pos');
        $this->handheld = $this->p6Device('mdev_p6_handheld', 'handheld');
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
        $this->coffee = $this->p4Product('Coffee', '1.000', ['cooking_minutes' => 5]);
        $this->cake = $this->p4Product('Cake', '2.000', ['cooking_minutes' => 12]);
    }

    protected function p6Device(string $token, string $type, int $company = 100, int $branch = 10): Device
    {
        $this->seatingBranch($branch, $company);

        return Device::factory()->paired($token)->create([
            'company_id' => $company, 'branch_id' => $branch, 'device_type' => $type, 'status' => 'active', 'name' => 'P6 '.$type,
        ]);
    }

    /** @param array<string, string> $headers */
    protected function p6As(Device $device, string $method, string $uri, array $payload = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->json($method, $uri, $payload, $headers);
    }

    /** A logged-in staff member's call from a till / handheld that declares tablet-orders (or not). */
    protected function p6Staff(Device $device, int $staffId, string $method, string $uri, array $payload = [], bool $capable = true): TestResponse
    {
        return $this->p6As($device, $method, $uri, $payload, ['X-Staff-Token' => $this->p5StaffToken($device, $staffId)]
            + ($capable ? ['X-Pos-Capabilities' => 'tablet-orders'] : []));
    }

    /** @return array<string, mixed> */
    protected function p6Line(int $productId, int $qty = 1, array $addonIds = []): array
    {
        return ['product_id' => $productId, 'qty' => $qty, 'addon_ids' => $addonIds, 'notes' => null];
    }

    /** @param array<string, mixed> $override */
    protected function p6Submit(array $override = [], ?Device $tablet = null): TestResponse
    {
        return $this->p6As($tablet ?? $this->tablet, 'POST', '/api/v1/device/tablet/orders', array_replace([
            'client_uuid' => (string) Str::uuid(), 'order_type' => 'quick', 'lines' => [$this->p6Line($this->coffee, 2)],
            'payment' => 'cash',
        ], $override));
    }

    protected function p6Row(string $tabletOrderUuid): TabletOrder
    {
        return TabletOrder::query()->where('uuid', $tabletOrderUuid)->firstOrFail();
    }

    protected function p6Order(string $orderUuid): Order
    {
        return Order::query()->where('uuid', $orderUuid)->firstOrFail();
    }

    /** A spend-based reward: $points points = $value OMR off. */
    protected function p6Rule(int $company = 100, int $points = 100, string $value = '0.500', string $name = 'Coffee points'): int
    {
        return (int) DB::table('pos_loyalty_rules')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => $name, 'type' => 'spend_based',
            'config_json' => json_encode(['points_per_omr' => 10, 'redemption_points' => $points, 'redemption_value' => $value]),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function p6Customer(string $phone, string $name = 'Secret Name', int $company = 100): int
    {
        return (int) DB::table('pos_customers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => $name, 'phone' => $phone,
            'phone_canonical' => ltrim($phone, '+'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function p6Account(int $customerId, int $ruleId, int $points, int $company = 100): void
    {
        $key = ['company_id' => $company, 'customer_id' => $customerId, 'loyalty_rule_id' => $ruleId];
        if (DB::table('pos_loyalty_accounts')->where($key)->exists()) {
            DB::table('pos_loyalty_accounts')->where($key)->update(['point_balance' => $points, 'updated_at' => now()]);

            return;
        }
        DB::table('pos_loyalty_accounts')->insert($key + ['uuid' => (string) Str::uuid(), 'point_balance' => $points,
            'stamp_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Pay an order in cash through the existing order.pay sync event. */
    protected function p6PayCash(Device $device, string $orderUuid, int $amountBaisas, int $staffId = 7): TestResponse
    {
        $at = now()->toIso8601String();
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
            'payload' => ['order_uuid' => $orderUuid, 'paid_at' => $at, 'staff_id' => $staffId,
                'payments' => [['method' => 'cash', 'amount_baisas' => $amountBaisas, 'change_given_baisas' => 0]]],
        ]]]);
    }

    protected function p6EnableNumbering(int $company = 100): void
    {
        DB::table('pos_company_settings')->insert(['company_id' => $company, 'key' => 'order_numbering',
            'value' => json_encode(['enabled' => true, 'prefix' => 'KLD-', 'pad' => 4, 'scope' => 'branch', 'daily_reset' => false]),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
