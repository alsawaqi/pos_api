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
 * Device-to-device order transfer. A device parks an unpaid order addressed to
 * another device in the SAME branch (order.transfer sync event → held mirror +
 * transfer stamp); the target lists it (GET /device/transfers/incoming) and
 * CLAIMS it (POST /device/transfers/{uuid}/claim) into its own cart, at which
 * point ownership moves and it leaves every other inbox. main POS ↔ handheld,
 * bidirectional, online-only.
 */
class DeviceOrderTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the transferred order's cashier (7, source) and the
        // claimer's cashier (9, on pay) both belong to the device tenant.
        $this->seedPosStaff([7, 9]);
    }

    private function device(string $token, int $company = 100, int $branch = 10, string $name = 'Device', string $type = 'pos_terminal'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => $company,
            'branch_id' => $branch,
            'name' => $name,
            'device_type' => $type,
        ]);
    }

    private function seedCatalogue(int $company = 100): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'Latte', 'base_price' => 1.500, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_addons')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'add_on_group_id' => 1, 'name' => 'Extra shot', 'price_delta' => 0.500, 'status' => 'active'] + $t,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transferEvent(string $orderUuid, int $targetDeviceId): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.transfer',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'target_device_id' => $targetDeviceId,
                'order' => [
                    'uuid' => $orderUuid,
                    'order_type' => 'quick',
                    'source' => 'main_pos',
                    'staff_id' => 7,
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 3500,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 3500,
                    'lines' => [[
                        'product_id' => 1,
                        'qty' => 1,
                        'unit_price_baisas' => 3500,
                        'line_total_baisas' => 3500,
                        'addons' => [['add_on_id' => 1, 'price_delta_baisas' => 500]],
                    ]],
                ],
            ],
        ];
    }

    /**
     * The pos_device guard (viaRequest → RequestGuard) caches its user, and
     * the test app is reused across requests — so drop the cached user before
     * every switch of bearer token, exactly as each real HTTP request would
     * re-resolve. Every request in this file goes through this helper.
     */
    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->as($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    private function assertProcessed(TestResponse $res, int $index = 0): void
    {
        $res->assertOk();
        $this->assertSame('processed', $res->json("data.results.$index.status"), (string) json_encode($res->json("data.results.$index")));
    }

    public function test_branch_devices_lists_colleagues_and_excludes_self_and_other_branches(): void
    {
        $this->device('mdev_a', 100, 10, 'Main POS');
        $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $this->device('mdev_other_branch', 100, 20, 'Other Branch');
        $this->device('mdev_other_co', 200, 10, 'Other Company');

        $res = $this->as('mdev_a')->getJson('/api/v1/device/branch-devices');

        $res->assertOk();
        $names = collect($res->json('data.devices'))->pluck('name')->all();
        $this->assertSame(['Handheld'], $names); // only the same-branch colleague
        $this->assertSame('handheld', $res->json('data.devices.0.device_type'));
    }

    public function test_transfer_sends_an_order_and_the_target_sees_it_but_the_sender_does_not(): void
    {
        $this->seedCatalogue();
        $from = $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_a', [$this->transferEvent($uuid, (int) $to->id)]);
        $this->assertProcessed($res);
        $this->assertSame((int) $to->id, $res->json('data.results.0.result.transferred_to_device_id'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertSame((int) $to->id, (int) $order->transferred_to_device_id);
        $this->assertSame((int) $from->id, (int) $order->transferred_from_device_id);
        $this->assertSame((int) $from->id, (int) $order->device_id); // still owned by sender until claimed

        // Target's inbox shows it, with the sender's name + full line snapshot.
        $inbox = $this->as('mdev_b')->getJson('/api/v1/device/transfers/incoming');
        $inbox->assertOk();
        $this->assertSame(1, $inbox->json('meta.count'));
        $this->assertSame('Main POS', $inbox->json('data.transfers.0.transferred_from_name'));
        $this->assertSame(3500, $inbox->json('data.transfers.0.grand_total_baisas'));
        $this->assertSame(500, $inbox->json('data.transfers.0.items.0.addons.0.price_delta_baisas'));

        // The sender's OWN inbox is empty — it sent it away.
        $senderInbox = $this->as('mdev_a')->getJson('/api/v1/device/transfers/incoming');
        $this->assertSame(0, $senderInbox->json('meta.count'));
    }

    public function test_claim_takes_ownership_clears_the_target_and_lets_the_claimer_pay(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_a', [$this->transferEvent($uuid, (int) $to->id)]));

        $claim = $this->as('mdev_b')->postJson("/api/v1/device/transfers/{$uuid}/claim");
        $claim->assertOk();
        $this->assertSame($uuid, $claim->json('data.order.uuid'));
        $this->assertSame('Main POS', $claim->json('data.order.transferred_from_name'));

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame((int) $to->id, (int) $order->device_id); // ownership moved
        $this->assertNull($order->transferred_to_device_id);        // left every inbox

        // It's gone from the inbox now.
        $this->assertSame(0, $this->as('mdev_b')->getJson('/api/v1/device/transfers/incoming')->json('meta.count'));

        // The claimer completes payment against the same uuid.
        $pay = $this->push('mdev_b', [
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.create',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => ['order' => [
                    'uuid' => $uuid, 'order_type' => 'quick', 'source' => 'handheld', 'staff_id' => 9,
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 3500, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 3500,
                    'gps' => ['lat' => 0, 'lng' => 0],
                    'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3500, 'line_total_baisas' => 3500]],
                ]],
            ],
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.pay',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => ['order_uuid' => $uuid, 'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 3500, 'change_given_baisas' => 0]]],
            ],
        ]);
        $this->assertProcessed($pay, 0);
        $this->assertProcessed($pay, 1);
        $this->assertSame(Order::STATUS_PAID, Order::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_a_second_claim_of_the_same_transfer_fails(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_a', [$this->transferEvent($uuid, (int) $to->id)]));

        $this->as('mdev_b')->postJson("/api/v1/device/transfers/{$uuid}/claim")->assertOk();
        $second = $this->as('mdev_b')->postJson("/api/v1/device/transfers/{$uuid}/claim");
        $second->assertStatus(409);
        $this->assertSame('transfer_unavailable', $second->json('errors.0.code'));
    }

    public function test_a_device_cannot_claim_a_transfer_addressed_to_someone_else(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $this->device('mdev_c', 100, 10, 'Second Register');
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_a', [$this->transferEvent($uuid, (int) $to->id)]));

        // Device C (not the target) must not be able to claim it.
        $res = $this->as('mdev_c')->postJson("/api/v1/device/transfers/{$uuid}/claim");
        $res->assertStatus(409);
        $this->assertNull(collect($this->as('mdev_c')->getJson('/api/v1/device/transfers/incoming')->json('data.transfers'))->firstWhere('uuid', $uuid));
    }

    public function test_transfer_to_a_device_outside_the_branch_fails(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $other = $this->device('mdev_other', 100, 20, 'Other Branch');
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_a', [$this->transferEvent($uuid, (int) $other->id)]);
        $res->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('active device at this branch', $res->json('data.results.0.result.error'));
        $this->assertSame(0, Order::query()->where('uuid', $uuid)->count()); // nothing written
    }

    public function test_transfer_to_self_fails(): void
    {
        $this->seedCatalogue();
        $self = $this->device('mdev_a', 100, 10, 'Main POS');
        $uuid = (string) Str::uuid();

        $res = $this->push('mdev_a', [$this->transferEvent($uuid, (int) $self->id)]);
        $res->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('sending device', $res->json('data.results.0.result.error'));
    }

    public function test_a_joined_table_bill_keeps_its_tables_through_incoming_and_claim(): void
    {
        // HH-8 regression: a dine-in bill spanning JOINED tables (primary T1
        // covering T2+T3) must arrive on the receiving device with the full
        // table set — the snapshot used to drop joined_table_ids, silently
        // shrinking the bill to one table after a transfer.
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');

        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_tables')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T1'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T2'] + $t,
            ['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 1, 'label' => 'T3'] + $t,
        ]);

        $uuid = (string) Str::uuid();
        $event = $this->transferEvent($uuid, (int) $to->id);
        $event['payload']['order']['order_type'] = 'dine_in';
        $event['payload']['order']['table_id'] = 1;
        $event['payload']['order']['joined_table_ids'] = [2, 3];
        $this->assertProcessed($this->push('mdev_a', [$event]));

        $incoming = $this->as('mdev_b')->getJson('/api/v1/device/transfers/incoming');
        $incoming->assertOk();
        $this->assertSame(1, $incoming->json('data.transfers.0.table_id'));
        $this->assertSame([2, 3], $incoming->json('data.transfers.0.joined_table_ids'));

        $claim = $this->as('mdev_b')->postJson("/api/v1/device/transfers/{$uuid}/claim");
        $claim->assertOk();
        $this->assertSame(1, $claim->json('data.order.table_id'));
        $this->assertSame([2, 3], $claim->json('data.order.joined_table_ids'));
    }

    public function test_a_single_table_transfer_carries_an_empty_joined_set(): void
    {
        $this->seedCatalogue();
        $this->device('mdev_a', 100, 10, 'Main POS');
        $to = $this->device('mdev_b', 100, 10, 'Handheld', 'handheld');
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push('mdev_a', [$this->transferEvent($uuid, (int) $to->id)]));

        $claim = $this->as('mdev_b')->postJson("/api/v1/device/transfers/{$uuid}/claim");
        $claim->assertOk();
        $this->assertSame([], $claim->json('data.order.joined_table_ids'));
    }
}
