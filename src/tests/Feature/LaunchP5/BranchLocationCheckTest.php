<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Actions\Device\GeofenceGuard;
use App\Models\Branch;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P5 add-on (owner request 2026-10-05) — the branch "Location check"
 * switch (pos_branches.location_check_enabled). Off: staff can log in and
 * sell from any location; the branch keeps its map location.
 *  - GeofenceGuard::requirement() answers SKIP for such a branch before any
 *    other rule (never branch_location_missing);
 *  - the config (full and delta) and activation send the EFFECTIVE
 *    location_mode: 'any' while the check is off, else the device's own;
 *  - a toggle reaches the branch's devices on their next delta.
 * With the check on (the default) today's behaviour is unchanged.
 *
 * Branch 10 sits at (23.5880, 58.3829) with a 300 m fence (+100 m tolerance).
 */
class BranchLocationCheckTest extends TestCase
{
    use RefreshDatabase;

    private const INSIDE = ['lat' => 23.5880, 'lng' => 58.3829];

    private const OUTSIDE = ['lat' => 23.6050, 'lng' => 58.4000];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_branches')->insert([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main',
            'latitude' => 23.5880000, 'longitude' => 58.3829000, 'geofence_radius_m' => 300,
            'default_order_type' => 'quick', 'status' => 'active',
        ] + $t);
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Item', 'base_price' => 3.000, 'status' => 'active',
        ] + $t);
        DB::table('pos_staff')->insert([
            'id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Ali',
            'pin_hash' => Hash::make('123456'), 'position' => 'cashier', 'status' => 'active',
        ] + $t);
    }

    private function device(string $mode = 'branch'): Device
    {
        return Device::factory()->paired('mdev_loc_'.$mode)->create(['company_id' => 100, 'branch_id' => 10, 'location_mode' => $mode]);
    }

    private function switchCheck(bool $on): void
    {
        DB::table('pos_branches')->where('id', 10)->update(['location_check_enabled' => $on, 'updated_at' => now()]);
    }

    /** @param  array{lat: float, lng: float}|null  $gps */
    private function login(Device $device, ?array $gps): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken((string) $device->plainTextToken)
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'] + ($gps === null ? [] : $gps));
    }

    /** @param  array{lat: float, lng: float}|null  $gps */
    private function create(Device $device, ?array $gps, ?Carbon $at = null): string
    {
        $at ??= now();
        $order = ['uuid' => (string) Str::uuid(), 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => 7,
            'opened_at' => $at->toIso8601String(), 'subtotal_baisas' => 3000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0,
            'grand_total_baisas' => 3000,
            'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
        ] + ($gps === null ? [] : ['gps' => $gps]);
        Auth::forgetGuards();

        return (string) $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.create', 'client_timestamp' => $at->toIso8601String(),
            'payload' => ['order' => $order],
        ]]])->assertOk()->json('data.results.0.status');
    }

    private function config(Device $device, ?Carbon $since = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->getJson($since === null ? '/api/v1/device/config'
            : '/api/v1/device/config/delta?since='.urlencode($since->toIso8601String()))->assertOk();
    }

    public function test_with_the_check_off_login_and_sales_work_from_any_location(): void
    {
        $device = $this->device();
        $this->switchCheck(false);

        $this->login($device, self::OUTSIDE)->assertOk();
        $this->login($device, null)->assertOk();
        $this->assertSame('processed', $this->create($device, self::OUTSIDE));
        $this->assertSame('processed', $this->create($device, null));
        // The branch keeps its location.
        $branch = DB::table('pos_branches')->where('id', 10)->first(['latitude', 'longitude']);
        $this->assertSame([23.588, 58.3829], [(float) $branch->latitude, (float) $branch->longitude]);
    }

    public function test_with_the_check_on_todays_fence_is_unchanged(): void
    {
        $device = $this->device();

        $this->login($device, self::OUTSIDE)->assertStatus(422)->assertJsonPath('errors.0.code', 'outside_geofence');
        $this->login($device, null)->assertStatus(422)->assertJsonPath('errors.0.code', 'location_required');
        $this->login($device, self::INSIDE)->assertOk();
        $this->assertSame('failed', $this->create($device, self::OUTSIDE));
        $this->assertSame('failed', $this->create($device, null));
        $this->assertSame('processed', $this->create($device, self::INSIDE));
    }

    public function test_a_branch_without_coordinates_and_the_check_off_is_never_location_missing(): void
    {
        DB::table('pos_branches')->where('id', 10)->update(['latitude' => null, 'longitude' => null]);
        $device = $this->device();
        $this->login($device, null)->assertStatus(422)->assertJsonPath('errors.0.code', 'branch_location_missing');

        $this->switchCheck(false);
        $this->login($device, null)->assertOk();
        $this->assertSame('processed', $this->create($device, null));

        $guard = app(GeofenceGuard::class);
        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device, Branch::query()->findOrFail(10)));
    }

    public function test_a_sale_made_while_the_check_was_off_is_not_fenced_when_it_syncs_after_it_is_back_on(): void
    {
        // The admin turned the check off 07:00–08:00 and on again (now 09:00).
        DB::table('pos_branches')->where('id', 10)->update(['location_check_enabled' => true, 'location_check_off_since' => null,
            'location_check_off_windows' => json_encode([
                ['from' => '2026-10-05T07:00:00+00:00', 'until' => '2026-10-05T08:00:00+00:00'],
            ])]);
        $device = $this->device();
        $offline = Carbon::parse('2026-10-05 07:30:00', 'UTC');

        $this->assertSame('processed', $this->create($device, self::OUTSIDE, $offline));
        $this->assertSame('processed', $this->create($device, null, $offline));

        // Made after the check was back on: still fenced.
        $after = Carbon::parse('2026-10-05 08:30:00', 'UTC');
        $this->assertSame('failed', $this->create($device, self::OUTSIDE, $after));
        $this->assertSame('failed', $this->create($device, null, $after));
        $this->assertSame('processed', $this->create($device, self::INSIDE, $after));
        // Live actions (no event time) follow the switch as it is now.
        $this->login($device, self::OUTSIDE)->assertStatus(422)->assertJsonPath('errors.0.code', 'outside_geofence');
    }

    public function test_the_guard_reads_every_off_window_and_the_open_period(): void
    {
        $guard = app(GeofenceGuard::class);
        $device = $this->device();
        DB::table('pos_branches')->where('id', 10)->update(['location_check_enabled' => false,
            'location_check_off_since' => '2026-10-05 08:45:00', 'location_check_off_windows' => json_encode([
                ['from' => '2026-10-04T10:00:00+00:00', 'until' => '2026-10-04T11:00:00+00:00'],
                ['from' => '2026-10-05T06:00:00+00:00', 'until' => '2026-10-05T07:00:00+00:00'],
                ['from' => 'not a time', 'until' => '2026-10-05T08:00:00+00:00'],
            ])]);
        $branch = fn (): Branch => Branch::query()->findOrFail(10);
        $at = fn (string $time): Carbon => Carbon::parse($time, 'UTC');

        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device, $branch(), $at('2026-10-05 08:50:00')));
        DB::table('pos_branches')->where('id', 10)->update(['location_check_enabled' => true, 'location_check_off_since' => null]);
        foreach (['2026-10-04 10:30:00', '2026-10-05 06:00:00', '2026-10-05 06:59:59'] as $inside) {
            $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device, $branch(), $at($inside)), $inside);
        }
        foreach (['2026-10-04 11:00:00', '2026-10-05 07:00:00', '2026-10-05 07:30:00', '2026-10-05 08:50:00'] as $outside) {
            $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($device, $branch(), $at($outside)), $outside);
        }
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($device, $branch()));
    }

    public function test_the_config_sends_the_effective_location_mode_and_a_toggle_reaches_the_delta(): void
    {
        $branchDevice = $this->device('branch');
        $anyDevice = $this->device('any');
        $this->config($branchDevice)->assertJsonPath('data.device.location_mode', 'branch')
            ->assertJsonPath('data.branch.location_check_enabled', true);
        $this->config($anyDevice)->assertJsonPath('data.device.location_mode', 'any');

        // The admin turns the check off: the next delta carries 'any'.
        $cursor = now()->copy();
        $this->travel(1)->minutes();
        $this->switchCheck(false);
        $this->config($branchDevice, $cursor)->assertJsonPath('meta.mode', 'delta')
            ->assertJsonPath('data.device.location_mode', 'any')
            ->assertJsonPath('data.branch.location_check_enabled', false);
        $this->config($branchDevice)->assertJsonPath('data.device.location_mode', 'any');
        $this->config($anyDevice, $cursor)->assertJsonPath('data.device.location_mode', 'any');

        // ... and back on: the device's own mode again.
        $cursor = now()->copy();
        $this->travel(1)->minutes();
        $this->switchCheck(true);
        $this->config($branchDevice, $cursor)->assertJsonPath('data.device.location_mode', 'branch')
            ->assertJsonPath('data.branch.location_check_enabled', true);
        $this->config($anyDevice, $cursor)->assertJsonPath('data.device.location_mode', 'any');
    }
}
