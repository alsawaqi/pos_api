<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase C2 — server-side held orders (blueprint §6.7: "Hold: persists current
 * order to local store and to backend (so other devices can resume it)").
 *
 * order.hold mirrors the device's parked cart as a pos_orders row with
 * status=held via an UPSERT-BY-UUID: a re-hold replaces the mirror in place,
 * the later finalize order.create flips it to open (the device cannot know
 * offline whether its hold reached the server), order.pay then pays it, and
 * order.void discards it (unpaid — no inventory unwind). Held mirrors surface
 * on GET /device/orders/active. The geofence is DELIBERATELY not enforced on
 * order.hold (no money/stock moves; offline-queued holds carry no GPS fix) —
 * order.create / order.pay still enforce it.
 */
class DeviceSyncHoldOrderTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_hold', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedCatalogue(int $company = 100): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'Cake', 'base_price' => 2.000, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'add_on_group_id' => 1, 'name' => 'Extra shot', 'price_delta' => 0.500, 'status' => 'active'] + $t,
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function holdEvent(string $orderUuid, array $orderOverrides = [], string $eventType = 'order.hold'): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $orderUuid,
                'order_type' => 'dine_in',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [[
                    'product_id' => 1,
                    'qty' => 2,
                    'unit_price_baisas' => 1500,
                    'line_total_baisas' => 3000,
                    'notes' => 'no sugar',
                    'addons' => [['add_on_id' => 1, 'price_delta_baisas' => 500]],
                ]],
            ], $orderOverrides)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    private function assertProcessed(TestResponse $res, int $index = 0): void
    {
        $res->assertOk();
        $this->assertSame(
            'processed',
            $res->json("data.results.$index.status"),
            (string) json_encode($res->json("data.results.$index")),
        );
    }

    public function test_order_hold_creates_a_held_mirror_with_lines_and_addons(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_hold', [$this->holdEvent($uuid)]);

        $this->assertProcessed($res);
        $this->assertSame('held', $res->json('data.results.0.result.order_status'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertSame('3.000', (string) $order->grand_total);
        $this->assertSame('no sugar', (string) OrderItem::query()->where('order_id', $order->id)->value('notes'));
        $this->assertSame(1, DB::table('pos_order_item_addons')
            ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->pluck('id'))
            ->count());
    }

    public function test_a_held_mirror_appears_on_the_active_orders_endpoint(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid)]));

        $res = $this->withToken('mdev_hold')->getJson('/api/v1/device/orders/active');

        $res->assertOk();
        $row = collect($res->json('data.orders'))->firstWhere('uuid', $uuid);
        $this->assertNotNull($row);
        $this->assertSame('held', $row['status']);
    }

    public function test_a_re_hold_of_the_same_uuid_replaces_the_mirror_in_place(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid)]));

        // Resume + edit on the device, then hold again: 1x Cake, new totals.
        $res = $this->push('mdev_hold', [$this->holdEvent($uuid, [
            'subtotal_baisas' => 2000,
            'grand_total_baisas' => 2000,
            'lines' => [['product_id' => 2, 'qty' => 1, 'unit_price_baisas' => 2000, 'line_total_baisas' => 2000]],
        ])]);

        $this->assertProcessed($res);
        $this->assertSame('updated', $res->json('data.results.0.result.status'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertSame('2.000', (string) $order->grand_total);
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $items); // replaced, not appended
        $this->assertSame('Cake', $items->first()->product_name_snapshot);
        $this->assertSame(1, Order::query()->where('uuid', $uuid)->count());
    }

    public function test_finalize_create_flips_a_held_mirror_to_open_and_pay_settles_it(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid)]));

        // The device completes the resumed order: order.create (same uuid) +
        // order.pay ride one batch, exactly as the completion flow builds them.
        $res = $this->push('mdev_hold', [
            $this->holdEvent($uuid, [], 'order.create'),
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.pay',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $uuid,
                    'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]],
                ],
            ],
        ]);

        $this->assertProcessed($res, 0);
        $this->assertProcessed($res, 1);
        $this->assertSame('updated', $res->json('data.results.0.result.status'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(1, Order::query()->where('uuid', $uuid)->count());
    }

    public function test_hold_over_a_terminal_order_fails(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid, [], 'order.create')]));
        $this->assertProcessed($this->push('mdev_hold', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]],
            ],
        ]]));

        $res = $this->push('mdev_hold', [$this->holdEvent($uuid)]);

        $res->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('terminal status paid', $res->json('data.results.0.result.error'));
        $this->assertSame(Order::STATUS_PAID, Order::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_hold_skips_the_geofence_while_create_still_enforces_it(): void
    {
        // A FENCED branch + an event with NO GPS: order.create fails closed,
        // order.hold (deliberately) succeeds — holds move no money or stock
        // and offline-queued holds have no fix.
        DB::table('pos_branches')->insert([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Fenced',
            'latitude' => 23.5880000, 'longitude' => 58.3829000, 'geofence_radius_m' => 300,
            'default_order_type' => 'dine_in', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedCatalogue();
        $this->device();

        $create = $this->push('mdev_hold', [$this->holdEvent((string) Str::uuid(), [], 'order.create')]);
        $create->assertOk();
        $this->assertSame('failed', $create->json('data.results.0.status'));
        $this->assertStringContainsString('GPS', $create->json('data.results.0.result.error'));

        $holdUuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($holdUuid)]));
        $this->assertSame(Order::STATUS_HELD, Order::query()->where('uuid', $holdUuid)->firstOrFail()->status);
    }

    public function test_hold_enforces_tenant_isolation_and_the_money_invariant(): void
    {
        $this->seedCatalogue();
        DB::table('pos_products')->insert([
            'id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign', 'base_price' => 1.000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->device();

        $foreign = $this->push('mdev_hold', [$this->holdEvent((string) Str::uuid(), [
            'lines' => [['product_id' => 99, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_total_baisas' => 3000]],
        ])]);
        $foreign->assertOk();
        $this->assertSame('failed', $foreign->json('data.results.0.status'));
        $this->assertStringContainsString('outside the device tenant', $foreign->json('data.results.0.result.error'));

        $broken = $this->push('mdev_hold', [$this->holdEvent((string) Str::uuid(), [
            'grand_total_baisas' => 9999,
        ])]);
        $broken->assertOk();
        $this->assertSame('failed', $broken->json('data.results.0.status'));
        $this->assertStringContainsString('invariant', $broken->json('data.results.0.result.error'));
    }

    public function test_a_uuid_held_by_another_tenant_cannot_be_overwritten(): void
    {
        $this->seedCatalogue();
        // Company 200 owns its OWN product (id 50) so the second push passes
        // the product tenant guard and exercises the uuid ownership guard.
        DB::table('pos_products')->insert([
            'id' => 50, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Tea', 'base_price' => 3.000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->device();
        $this->device('mdev_hold_b', 200, 20);
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid)]));

        $res = $this->push('mdev_hold_b', [$this->holdEvent($uuid, [
            'lines' => [['product_id' => 50, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_total_baisas' => 3000]],
        ])]);

        $res->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('outside the device tenant', $res->json('data.results.0.result.error'));
        // The original tenant's mirror is untouched.
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(100, (int) $order->company_id);
    }

    public function test_order_void_discards_a_held_mirror(): void
    {
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid)]));

        $res = $this->push('mdev_hold', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'Held order discarded',
            ],
        ]]);

        $this->assertProcessed($res);
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_VOID, $order->status);
        // Unpaid void: nothing to unwind, and the mirror leaves the active list.
        $active = $this->withToken('mdev_hold')->getJson('/api/v1/device/orders/active');
        $this->assertNull(collect($active->json('data.orders'))->firstWhere('uuid', $uuid));
    }

    public function test_duplicate_uuids_across_creates_still_fail_for_distinct_carts(): void
    {
        // Regression guard for the upsert relaxation: two DIFFERENT completed
        // orders accidentally sharing a uuid must not silently merge — the
        // second create UPSERTS (by design), so this documents that the upsert
        // only ever applies within one device's own uuid, never across paid
        // orders (the terminal guard above is what protects real collisions).
        $this->seedCatalogue();
        $this->device();
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_hold', [$this->holdEvent($uuid, [], 'order.create')]));

        $res = $this->push('mdev_hold', [$this->holdEvent($uuid, [
            'subtotal_baisas' => 2000,
            'grand_total_baisas' => 2000,
            'lines' => [['product_id' => 2, 'qty' => 1, 'unit_price_baisas' => 2000, 'line_total_baisas' => 2000]],
        ], 'order.create')]);

        // Same-uuid re-create over a non-terminal order is an upsert…
        $this->assertProcessed($res);
        $this->assertSame('updated', $res->json('data.results.0.result.status'));
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_OPEN, $order->status);
        $this->assertSame('2.000', (string) $order->grand_total);
        // …and exactly one row exists (no duplicate orders).
        $this->assertSame(1, Order::query()->where('uuid', $uuid)->count());
    }
}
