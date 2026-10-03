<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use App\Models\Device;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P4 A5 — the manual "sold out" switch (owner decision 4).
 *
 *  - POST /device/products/{id}/sold-out: a manager or supervisor switches
 *    it alone; anyone else needs an approving manager (manager-approval
 *    positions). It applies to the device's branch, is audited and bumps
 *    the product so deltas re-emit it; GET /device/sold-out lists it.
 *  - The device config carries `sold_out` for this branch.
 *  - QR shows it greyed and refuses it; staff rounds hold it; a device
 *    sale of it still settles (never refused).
 */
final class SoldOutTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private Device $device;

    private int $soup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->device = $this->p4Device('mdev_p4_soldout', 'fixed_pos');
        foreach ([[7, 'cashier', 10], [8, 'supervisor', 10], [9, 'manager', 11], [10, 'waiter', 10]] as [$id, $position, $branch]) {
            PosStaff::create(['id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => $branch,
                'name' => 'Staff '.$id, 'position' => $position, 'status' => 'active', 'pin_hash' => Hash::make('1234'.$id)]);
        }
        $this->soup = $this->p4Product('Soup', '1.200');
    }

    private function toggle(bool $soldOut, int $staffId, ?int $approver = null, ?int $productId = null)
    {
        return $this->withToken('mdev_p4_soldout')->postJson('/api/v1/device/products/'.($productId ?? $this->soup).'/sold-out',
            ['sold_out' => $soldOut, 'staff_id' => $staffId] + ($approver === null ? [] : ['approver_staff_id' => $approver]));
    }

    public function test_the_position_rule_decides_who_switches_it_and_every_change_is_audited(): void
    {
        $this->toggle(true, 7)->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        $this->toggle(true, 7, 10)->assertStatus(403);
        $this->assertDatabaseCount('pos_product_sold_out', 0);

        // A cashier with an approving manager (any branch), a supervisor alone.
        $this->toggle(true, 7, 9)->assertOk()->assertJsonPath('data.sold_out', true)->assertJsonPath('data.changed', true);
        $this->assertDatabaseHas('pos_product_sold_out', ['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->soup, 'set_by_pos_staff_id' => 9]);
        $this->toggle(true, 8)->assertOk()->assertJsonPath('data.changed', false);
        $this->withToken('mdev_p4_soldout')->getJson('/api/v1/device/sold-out')->assertOk()
            ->assertJsonPath('data.product_ids', [$this->soup]);

        $this->toggle(false, 8)->assertOk()->assertJsonPath('data.sold_out', false);
        $this->assertDatabaseCount('pos_product_sold_out', 0);
        $this->assertSame(['product.sold_out.set', 'product.sold_out.cleared'], DB::table('pos_audit_logs')->orderBy('id')->pluck('event')->all());
        $this->assertSame(9, json_decode((string) DB::table('pos_audit_logs')->orderBy('id')->value('metadata'), true)['approver_staff_id']);

        // Another company's product is not found; staff must be this company's.
        $foreign = $this->p4Product('Foreign', '1.000', ['company_id' => 200]);
        $this->toggle(true, 8, null, $foreign)->assertStatus(404);
        $this->toggle(true, 999)->assertStatus(422);
    }

    public function test_the_config_carries_it_and_a_delta_re_emits_the_product_both_ways(): void
    {
        $since = now();
        $this->travel(1)->minutes();
        $this->toggle(true, 8)->assertOk();
        $delta = fn (): array => collect($this->withToken('mdev_p4_soldout')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))->assertOk()->json('data.products'))
            ->keyBy('id')->all();
        $this->assertTrue($delta()[$this->soup]['sold_out']);

        $since = now();
        $this->travel(1)->minutes();
        $this->toggle(false, 8)->assertOk();
        $this->assertFalse($delta()[$this->soup]['sold_out']);
    }

    public function test_qr_greys_and_refuses_it_staff_rounds_hold_it_and_a_device_sale_still_settles(): void
    {
        $this->toggle(true, 8)->assertOk();
        $session = $this->p4QrSession();
        $menu = collect($this->p4QrGet($session, '/api/v1/public/qr/menu')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertSame([false, 'sold_out', true], [$menu[$this->soup]['available'], $menu[$this->soup]['unavailable_reason'], $menu[$this->soup]['sold_out']]);
        $line = ['product_id' => $this->soup, 'qty' => 1, 'addon_ids' => [], 'notes' => ''];
        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout([$line]))->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_sold_out');

        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $this->device->id]);
        $round = $this->withToken('mdev_p4_soldout')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [$line]],
        ]]])->assertOk()->json('data.results.0.result');
        $this->assertSame('held', $round['outcome'], (string) json_encode($round));
        $this->assertSame('sold_out', $round['held_lines'][0]['reason']);

        $order = $this->p4Order([['product_id' => $this->soup, 'qty' => 1, 'unit_price_baisas' => 1200, 'line_total_baisas' => 1200]]);
        $this->p4Device('mdev_p4_soldout_till');
        app('auth')->forgetGuards();
        $result = $this->p4Push('mdev_p4_soldout_till', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
    }
}
