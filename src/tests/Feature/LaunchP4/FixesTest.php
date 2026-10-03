<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P4 A6 — fixes.
 *
 *  - H9: QR menus, QR checkout and staff table rounds read a product's daily
 *    window on the merchant's wall clock (Asia/Muscat), not UTC.
 *  - H10: deleted or switched-off taxes, void / comp reasons and delivery
 *    providers leave the device on the next delta; a sale or void naming a
 *    deleted reason settles and is flagged.
 *  - M5: staff table rounds sell products hidden from the QR menu.
 *  - L4: inactive products are left out of QR (see ChannelsTest too).
 */
final class FixesTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
    }

    public function test_h9_a_breakfast_window_is_read_on_the_muscat_clock(): void
    {
        $breakfast = $this->p4Product('Breakfast', '1.500', ['available_from' => '07:00:00', 'available_until' => '11:00:00']);
        $session = $this->p4QrSession();
        $line = ['product_id' => $breakfast, 'qty' => 1, 'addon_ids' => [], 'notes' => ''];
        $menu = fn (): array => collect($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->keyBy('id')->all();

        // 06:30 UTC is 10:30 in Muscat: breakfast is on.
        $this->travelTo(Carbon::parse('2026-10-03 06:30:00', 'UTC'));
        $this->assertTrue($menu()[$breakfast]['available']);
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$line]])->assertOk();

        // 08:00 UTC is 12:00 in Muscat: it is over (UTC would still say 08:00).
        $this->travelTo(Carbon::parse('2026-10-03 08:00:00', 'UTC'));
        $this->assertSame('outside_availability_window', $menu()[$breakfast]['unavailable_reason']);
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$line]])->assertStatus(422);
    }

    public function test_h10_retired_reasons_taxes_and_providers_leave_the_device_and_a_sale_using_one_settles_flagged(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $this->p4Device('mdev_p4_h10');
        $t = ['created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00'];
        DB::table('pos_comp_reasons')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'code' => 'STAFF', 'name' => 'Staff meal', 'is_active' => true] + $t);
        DB::table('pos_comp_reasons')->insert(['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'code' => 'WAIT', 'name' => 'Long wait', 'is_active' => true] + $t);
        DB::table('pos_void_reasons')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'code' => 'MISTAKE', 'name' => 'Mistake', 'is_active' => true] + $t);
        $provider = (int) DB::table('pos_delivery_providers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat', 'is_active' => true] + $t);
        $since = now();
        $this->travel(1)->minutes();
        DB::table('pos_comp_reasons')->where('id', 1)->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('pos_comp_reasons')->where('id', 2)->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('pos_void_reasons')->where('id', 1)->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('pos_delivery_providers')->where('id', $provider)->update(['is_active' => false, 'updated_at' => now()]);

        $deleted = $this->withToken('mdev_p4_h10')->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))
            ->assertOk()->json('data.deleted');
        $this->assertSame([1, 2], $deleted['comp_reasons']);
        $this->assertSame([1], $deleted['void_reasons']);
        $this->assertSame([$provider], $deleted['delivery_providers']);

        // A paid sale cached with the deleted reasons settles, flagged.
        $latte = $this->p4Product('Latte', '1.000');
        $order = $this->p4Order([['product_id' => $latte, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]], 0, null, [
            'comp_total_baisas' => 1000, 'grand_total_baisas' => 0,
            'comps' => [['comp_reason_id' => 1, 'amount_baisas' => 1000, 'line_index' => 0, 'staff_id' => 7]],
        ]);
        $result = $this->p4Push('mdev_p4_h10', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertContains('comp_reason_deleted:1', $result['result']['integrity_flags']);

        $void = $this->p4Push('mdev_p4_h10', [['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $order['uuid'], 'void_reason_id' => 1]]])->json('data.results.0');
        $this->assertSame('processed', $void['status'], (string) json_encode($void));
        $this->assertContains('void_reason_deleted:1', $void['result']['integrity_flags']);
        $this->assertSame('Mistake', DB::table('pos_orders')->where('uuid', $order['uuid'])->value('void_reason_label'));
    }

    public function test_m5_a_staff_round_sells_a_product_hidden_from_the_qr_menu(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $staffOnly = $this->p4Product('Staff special', '2.000', ['show_on_customer_tablet' => false]);
        $ack = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $staffOnly, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->json('data.results.0.result');
        $this->assertSame('appended', $ack['outcome'], (string) json_encode($ack));
        $this->assertSame(2000, $ack['total_baisas']);

        $session = $this->p4QrSession();
        $this->assertNotContains($staffOnly, array_column($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'), 'id'));
    }
}
