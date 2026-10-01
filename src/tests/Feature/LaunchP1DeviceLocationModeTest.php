<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\GeofenceGuard;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P1 B1 — decision 2a: each device works at "this branch location"
 * ('branch', geofenced, default) or at "any location" ('any'). The server
 * judges order.create / order.pay / order.deliver and staff login by the
 * device's mode (QR claim, QR settlement and table checkout are covered in
 * their own test classes). A sale made while the device was still 'any' is
 * not refused after a switch to 'branch'; a 'branch' device at a branch
 * without coordinates is refused rather than silently unfenced.
 *
 * Branch 10 sits at (23.5880, 58.3829) with a 300 m fence (+100 m tolerance).
 */
class LaunchP1DeviceLocationModeTest extends TestCase
{
    use RefreshDatabase;

    private const INSIDE = ['lat' => 23.5880, 'lng' => 58.3829];

    private const OUTSIDE = ['lat' => 23.6050, 'lng' => 58.4000];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_branches')->insert([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main',
            'latitude' => 23.5880000, 'longitude' => 58.3829000, 'geofence_radius_m' => 300,
            'default_order_type' => 'dine_in', 'status' => 'active',
        ] + $t);
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Item', 'base_price' => 3.000, 'status' => 'active',
        ] + $t);
        DB::table('pos_delivery_providers')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat', 'commission_percent' => 20.00, 'is_active' => true, 'sort_order' => 1,
        ] + $t);
        DB::table('pos_staff')->insert([
            'id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Ali',
            'pin_hash' => Hash::make('123456'), 'position' => 'cashier', 'status' => 'active',
        ] + $t);
    }

    /** @param  array<string, mixed>  $attributes */
    private function device(string $mode, array $attributes = []): Device
    {
        return Device::factory()->paired('mdev_loc_'.$mode)->create(['company_id' => 100, 'branch_id' => 10, 'location_mode' => $mode] + $attributes);
    }

    private function unfenceBranch(): void
    {
        DB::table('pos_branches')->where('id', 10)->update(['latitude' => null, 'longitude' => null]);
    }

    /** @param  array{lat: float, lng: float}|null  $gps */
    private function createEvent(string $uuid, ?array $gps, ?Carbon $at = null, string $type = 'quick'): array
    {
        $at ??= now();
        $order = [
            'uuid' => $uuid, 'order_type' => $type, 'source' => 'main_pos', 'staff_id' => 7, 'opened_at' => $at->toIso8601String(),
            'subtotal_baisas' => 3000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 3000,
            'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
        ] + ($gps === null ? [] : ['gps' => $gps]);

        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.create',
            'client_timestamp' => $at->toIso8601String(), 'payload' => ['order' => $order]];
    }

    /** @param  array{lat: float, lng: float}|null  $gps */
    private function payEvent(string $uuid, ?array $gps, ?Carbon $at = null): array
    {
        $at ??= now();

        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at->toIso8601String(),
            'payload' => ['order_uuid' => $uuid, 'paid_at' => $at->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]]]
                + ($gps === null ? [] : ['gps' => $gps])];
    }

    /** @param  array{lat: float, lng: float}|null  $gps */
    private function deliverEvent(string $uuid, ?array $gps, ?Carbon $at = null): array
    {
        $at ??= now();

        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.deliver', 'client_timestamp' => $at->toIso8601String(),
            'payload' => ['order_uuid' => $uuid, 'delivered_at' => $at->toIso8601String(),
                'delivery' => ['provider_id' => 1, 'reference' => 'TLB-1', 'customer_phone' => '91234567']]
                + ($gps === null ? [] : ['gps' => $gps])];
    }

    /** @param  list<array<string, mixed>>  $events */
    private function push(Device $device, array $events): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
    }

    /** @return list<string> */
    private function statuses(TestResponse $response): array
    {
        return array_column($response->json('data.results'), 'status');
    }

    private function login(Device $device, ?array $gps): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken((string) $device->plainTextToken)
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'] + ($gps === null ? [] : $gps));
    }

    public function test_an_any_location_device_is_not_fenced_for_create_pay_deliver_or_login(): void
    {
        $device = $this->device('any');
        [$sale, $delivery] = [(string) Str::uuid(), (string) Str::uuid()];

        $response = $this->push($device, [
            $this->createEvent($sale, self::OUTSIDE), $this->payEvent($sale, null),
            $this->createEvent($delivery, null, null, 'delivery'), $this->deliverEvent($delivery, self::OUTSIDE),
        ]);

        $this->assertSame(['processed', 'processed', 'processed', 'processed'], $this->statuses($response));
        $this->assertSame(Order::STATUS_PAID, Order::query()->where('uuid', $sale)->value('status'));
        $this->assertSame(Order::STATUS_PENDING_VERIFICATION, Order::query()->where('uuid', $delivery)->value('status'));
        $this->login($device, null)->assertOk();
        $this->login($device, self::OUTSIDE)->assertOk();
    }

    public function test_a_branch_device_keeps_the_fence_for_create_pay_deliver_and_login(): void
    {
        $device = $this->device('branch');
        [$sale, $delivery] = [(string) Str::uuid(), (string) Str::uuid()];

        $response = $this->push($device, [
            $this->createEvent((string) Str::uuid(), self::OUTSIDE), $this->createEvent((string) Str::uuid(), null),
            $this->createEvent($sale, self::INSIDE), $this->payEvent($sale, self::OUTSIDE), $this->payEvent($sale, null),
            $this->createEvent($delivery, self::INSIDE, null, 'delivery'), $this->deliverEvent($delivery, self::OUTSIDE),
            $this->deliverEvent($delivery, null),
        ]);

        $this->assertSame(['failed', 'failed', 'processed', 'failed', 'failed', 'processed', 'failed', 'failed'], $this->statuses($response));
        $this->assertStringContainsString('geofence', (string) $response->json('data.results.0.result.error'));
        $this->assertStringContainsString('GPS fix is required', (string) $response->json('data.results.4.result.error'));
        $this->assertSame(Order::STATUS_OPEN, Order::query()->where('uuid', $sale)->value('status'));
        $this->assertSame(Order::STATUS_OPEN, Order::query()->where('uuid', $delivery)->value('status'));

        $this->login($device, null)->assertStatus(422)->assertJsonPath('errors.0.code', 'location_required');
        $this->login($device, self::OUTSIDE)->assertStatus(422)->assertJsonPath('errors.0.code', 'outside_geofence');
        $this->login($device, self::INSIDE)->assertOk();
    }

    public function test_sales_made_while_the_device_was_any_are_not_refused_after_switching_to_branch(): void
    {
        // 'any' from 10:00 until the admin switched it to 'branch' at 11:00.
        $device = $this->device('branch', [
            'location_any_started_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
            'location_mode_since' => Carbon::parse('2026-10-01 11:00:00', 'UTC'),
        ]);
        $madeWhileAny = Carbon::parse('2026-10-01 10:30:00', 'UTC');
        $madeBeforeAny = Carbon::parse('2026-10-01 09:30:00', 'UTC');
        $madeAfterSwitch = Carbon::parse('2026-10-01 11:30:00', 'UTC');
        [$late, $before, $after] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $delivery = (string) Str::uuid();

        $response = $this->push($device, [
            $this->createEvent($late, self::OUTSIDE, $madeWhileAny), $this->payEvent($late, null, $madeWhileAny),
            $this->createEvent($delivery, null, $madeWhileAny, 'delivery'), $this->deliverEvent($delivery, null, $madeWhileAny),
            $this->createEvent($before, self::OUTSIDE, $madeBeforeAny),
            $this->createEvent($after, self::OUTSIDE, $madeAfterSwitch),
        ]);

        $this->assertSame(['processed', 'processed', 'processed', 'processed', 'failed', 'failed'], $this->statuses($response));
        $this->assertSame(Order::STATUS_PAID, Order::query()->where('uuid', $late)->value('status'));
        // A live action happens now, after the switch: fenced.
        $this->login($device, self::OUTSIDE)->assertStatus(422)->assertJsonPath('errors.0.code', 'outside_geofence');
    }

    public function test_a_branch_device_at_a_branch_without_coordinates_is_refused_not_unfenced(): void
    {
        $this->unfenceBranch();
        $branchOnly = $this->device('branch');
        [$sale, $delivery] = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ([$sale => 'quick', $delivery => 'delivery'] as $uuid => $type) {
            Order::query()->create(['uuid' => $uuid, 'company_id' => 100, 'branch_id' => 10, 'device_id' => $branchOnly->id,
                'order_type' => $type, 'source' => 'main_pos', 'status' => 'open', 'subtotal' => '3.000', 'discount_total' => '0.000',
                'tax_total' => '0.000', 'grand_total' => '3.000', 'comp_total' => '0.000', 'opened_at' => now()]);
        }

        $response = $this->push($branchOnly, [
            $this->createEvent((string) Str::uuid(), self::INSIDE), $this->payEvent($sale, self::INSIDE),
            $this->deliverEvent($delivery, self::INSIDE),
        ]);

        $this->assertSame(['failed', 'failed', 'failed'], $this->statuses($response));
        foreach ([0, 1, 2] as $i) {
            $this->assertStringContainsString('branch has no location set', (string) $response->json("data.results.$i.result.error"));
        }
        $this->login($branchOnly, self::INSIDE)->assertStatus(422)->assertJsonPath('errors.0.code', 'branch_location_missing');

        // The same branch with an 'any' device works (what the migration does).
        $any = $this->device('any');
        $this->assertSame(['processed'], $this->statuses($this->push($any, [$this->createEvent((string) Str::uuid(), null)])));
        $this->login($any, null)->assertOk();
    }

    public function test_the_config_bundle_and_full_or_delta_carry_the_device_location_mode(): void
    {
        foreach (['branch', 'any'] as $mode) {
            $device = $this->device($mode);
            foreach (['/api/v1/device/config', '/api/v1/device/config/delta?since='.urlencode(now()->subHour()->toIso8601String())] as $url) {
                Auth::forgetGuards();
                $this->withToken((string) $device->plainTextToken)->getJson($url)->assertOk()
                    ->assertJsonPath('data.device.uuid', $device->uuid)
                    ->assertJsonPath('data.device.location_mode', $mode);
            }
            $device->forceFill(['device_token' => null])->save();
        }
    }

    public function test_the_guard_decision_matrix(): void
    {
        $guard = app(GeofenceGuard::class);
        $fenced = Branch::query()->findOrFail(10);
        $open = (clone $fenced)->forceFill(['latitude' => null, 'longitude' => null]);
        $device = fn (array $attributes): Device => (new Device)->forceFill($attributes);

        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device(['location_mode' => 'any']), $fenced));
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($device(['location_mode' => 'branch']), $fenced));
        $this->assertSame(GeofenceGuard::BRANCH_LOCATION_MISSING, $guard->requirement($device(['location_mode' => 'branch']), $open));
        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device(['location_mode' => 'any']), $open));
        // Unknown values fail closed to 'branch'.
        $this->assertSame(GeofenceGuard::BRANCH_LOCATION_MISSING, $guard->requirement($device(['location_mode' => 'elsewhere']), $open));
        // A reviewed-replay snapshot carries no mode: the legacy rule.
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($device([]), $fenced));
        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($device([]), $open));
        // The 'any' window is half-open: [location_any_started_at, location_mode_since).
        $switched = $device(['location_mode' => 'branch', 'location_any_started_at' => now()->subHours(2), 'location_mode_since' => now()->subHour()]);
        $this->assertSame(GeofenceGuard::SKIP, $guard->requirement($switched, $fenced, now()->subHours(2)));
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($switched, $fenced, now()->subHour()));
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($switched, $fenced, now()->subHours(3)));
        $this->assertSame(GeofenceGuard::ENFORCE, $guard->requirement($switched, $fenced));
    }

    public function test_every_closed_any_period_is_remembered_not_only_the_latest(): void
    {
        // any 08:00-08:30, branch, any 10:00-11:00, branch since 11:00.
        $device = $this->device('branch', [
            'location_any_windows' => [['from' => '2026-10-01T08:00:00+00:00', 'until' => '2026-10-01T08:30:00+00:00']],
            'location_any_started_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
            'location_mode_since' => Carbon::parse('2026-10-01 11:00:00', 'UTC'),
        ]);
        $at = fn (string $time): Carbon => Carbon::parse('2026-10-01 '.$time, 'UTC');

        $response = $this->push($device, [
            $this->createEvent((string) Str::uuid(), self::OUTSIDE, $at('08:15:00')),
            $this->createEvent((string) Str::uuid(), self::OUTSIDE, $at('08:30:00')),
            $this->createEvent((string) Str::uuid(), self::OUTSIDE, $at('09:00:00')),
            $this->createEvent((string) Str::uuid(), self::OUTSIDE, $at('10:30:00')),
        ]);

        $this->assertSame(['processed', 'failed', 'failed', 'processed'], $this->statuses($response));
    }
}
