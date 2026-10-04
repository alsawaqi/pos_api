<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use App\Models\Product;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F2, review H2) — the P5 marker is sticky per
 * device: the first `auth_v: 1` (a sync event or an online call) stamps
 * pos_devices.auth_v_seen_at, and from then on a request from that device
 * without auth_v is treated as P5 (a missing block is `missing` on sync and
 * refused online), never `legacy`. config pos.require_auth_v treats every
 * device as P5. A device that never sent it keeps the old rules.
 */
class StickyP5MarkerTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
    }

    /** A 30 % manual discount (the cashier's maximum is 10 %), no block, no auth_v. @return array<string, mixed> */
    private function oldBuildSale(): array
    {
        return $this->p5Create((string) Str::uuid(), ['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000]]]);
    }

    /** @return array{0: Device, 1: TableSession, 2: Product, 3: array<string, mixed>} */
    private function table(): array
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('P5 table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 7];
        $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/round', $prefix + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3]],
        ])->assertOk()->assertJsonPath('data.outcome', 'appended');

        return [$device, $seat->refresh(), $product, $prefix];
    }

    public function test_a_device_that_sent_auth_v_once_is_a_p5_build_on_sync_from_then_on(): void
    {
        $this->p5Device('mdev_new');
        $this->p5Device('mdev_old');

        // A device that never sent the marker keeps the old rules.
        $this->p5Push('mdev_old', [$this->oldBuildSale()])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(['legacy'], DB::table('pos_approvals')->pluck('result')->all());
        $this->assertNull(DB::table('pos_devices')->where('device_token', hash('sha256', 'mdev_old'))->value('auth_v_seen_at'));

        // One P5 event stamps the device (even one with nothing gated) ...
        $this->p5Push('mdev_new', [$this->p5Create((string) Str::uuid(), [], ['auth_v' => 1])])->assertOk();
        $this->assertNotNull(DB::table('pos_devices')->where('device_token', hash('sha256', 'mdev_new'))->value('auth_v_seen_at'));

        // ... and its later event without auth_v is checked as P5: missing.
        DB::table('pos_approvals')->delete();
        $this->p5Push('mdev_new', [$this->oldBuildSale()])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame([['missing', 'no_authorization']], DB::table('pos_approvals')->get(['result', 'reason'])
            ->map(fn ($r): array => [$r->result, $r->reason])->all());
    }

    public function test_an_online_call_from_a_marked_device_without_auth_v_is_refused(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $cancel = fn (): array => $prefix + ['client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id,
            'qty' => 1, 'prepared' => false, 'reason' => 'Wrong item', 'cancelled_at' => now()->toIso8601String()];
        $url = '/api/v1/device/tables/'.$seat->uuid.'/cancel-line';

        // Not marked yet: review probe 4 — an old build's cancel needs nothing.
        $this->p5Online($device, 'POST', $url, $cancel())->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        $this->assertSame(['legacy'], DB::table('pos_approvals')->pluck('result')->all());

        // Any online call with auth_v marks the device (here a shift read).
        $this->p5Online($device, 'GET', '/api/v1/device/shift/current?staff_id=7&auth_v=1', [], $this->p5StaffToken($device, 7))->assertOk();
        $this->assertNotNull($device->refresh()->auth_v_seen_at);

        // Leaving auth_v out no longer falls back to the old rules: the
        // person's token is needed, and then the block.
        $this->p5Online($device, 'POST', $url, $cancel(), '')
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'staff_unverified');
        $this->p5Online($device, 'POST', $url, $cancel(), $this->p5StaffToken($device, 7))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        $this->assertSame(['legacy'], DB::table('pos_approvals')->pluck('result')->all());
    }

    public function test_require_auth_v_treats_every_device_as_a_p5_build(): void
    {
        config(['pos.require_auth_v' => true]);
        $this->p5Device('mdev_old');

        $this->p5Push('mdev_old', [$this->oldBuildSale()])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(['missing'], DB::table('pos_approvals')->pluck('result')->all());
        $this->assertNull(DB::table('pos_devices')->value('auth_v_seen_at'));
    }
}
