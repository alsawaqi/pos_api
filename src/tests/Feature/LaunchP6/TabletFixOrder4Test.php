<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 4 (LAUNCH-P6_A_FIX_ORDER_4.md) — F-15 (the staff
 * Row shows the charge / recovery state), F-16 (a payer shift on a
 * decommissioned device takes no payment) and F-17 (no second geofence check
 * on the holder's late pay).
 */
final class TabletFixOrder4Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    // ---- F-15 — the charge state on the staff Row ----

    /** @return array<string, mixed> the staff Row as $device sees it */
    private function row(string $tabletOrderUuid, ?Device $device = null): array
    {
        $device ??= $this->till;

        return collect($this->p6Staff($device, $device->is($this->till) ? 7 : 9, 'GET', '/api/v1/device/tablet-orders')->assertOk()
            ->json('data.orders'))->firstWhere('tablet_order_uuid', $tabletOrderUuid);
    }

    /** @return list<mixed> state, device, deadline set, held by this device, recovery needed */
    private function charge(array $row): array
    {
        return [$row['charge']['state'], $row['charge']['device_id'], $row['charge']['deadline_at'] !== null,
            $row['charge']['held_by_this_device'], $row['recovery_needed']];
    }

    public function test_f15_the_staff_row_shows_none_claimed_lapsed_before_and_after_the_sweeper_and_recovered(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $uuid = $order['tablet_order_uuid'];
        $handheld = (int) $this->handheld->id;

        $this->assertSame(['none', null, false, false, false], $this->charge($this->row($uuid)));

        $claim = $this->p6Staff($this->handheld, 9, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $order['order_uuid']])
            ->assertOk()->json('data');
        $this->assertSame(['claimed', $handheld, true, false, false], $this->charge($this->row($uuid)));
        $this->assertSame(['claimed', $handheld, true, true, false], $this->charge($this->row($uuid, $this->handheld)));
        $this->assertSame($claim['charge_deadline_at'], $this->row($uuid)['charge']['deadline_at']);

        // The holder goes offline: lapsed by time, then stamped by the sweeper.
        $this->travel(6)->minutes();
        $this->assertSame(['lapsed', $handheld, true, false, true], $this->charge($this->row($uuid)));
        Artisan::call('qr:sweep-stale-charges');
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $this->p6Order($order['order_uuid'])->charge_outcome);
        $this->assertSame(['lapsed', $handheld, true, true, true], $this->charge($this->row($uuid, $this->handheld)));

        // Moved to the counter with its facts kept: still needs recovery.
        $this->p6As($this->till, 'POST', '/api/v1/device/qr/fallback-to-counter', ['order_uuid' => $order['order_uuid']])->assertOk();
        $this->assertSame(['recovered', $handheld, true, false, true], $this->charge($this->row($uuid)));

        // Paid: nothing left to show.
        $this->p6PayCash($this->till, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(['none', null, false, false, false], $this->charge($this->row($uuid)));
    }

    public function test_f15_an_uncertain_card_result_and_the_staff_action_answers_carry_the_charge_state(): void
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $this->p6Staff($this->handheld, 9, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $order['order_uuid']])->assertOk();
        $this->p6Order($order['order_uuid'])->update(['charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN]);

        $this->assertSame(['uncertain', (int) $this->handheld->id, true, false, true], $this->charge($this->row($order['tablet_order_uuid'])));
        // The take answer is the same Row, seen by the device that called.
        $taken = $this->p6Staff($this->handheld, 9, 'POST', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/take")->assertOk()
            ->json('data.order');
        $this->assertSame(['uncertain', (int) $this->handheld->id, true, true, true], $this->charge($taken));
    }

    // ---- F-16 — a payer shift on a decommissioned device ----

    public function test_f16_a_payer_shift_on_a_decommissioned_device_leaves_the_payment_with_the_paying_devices_shift(): void
    {
        $open = function (string $token, int $staffId): string {
            $uuid = (string) Str::uuid();
            $this->p5Push($token, [$this->p5Event('shift.open', ['uuid' => $uuid, 'staff_id' => $staffId, 'shared_shift' => true,
                'opening_cash_baisas' => 5000, 'opened_at' => now()->subHour()->toIso8601String()], at: now()->subHour()->toIso8601String())])
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

            return $uuid;
        };
        $open('mdev_p6_till', 7);
        $handheldShift = $open('mdev_p6_handheld', 9);
        // The till is decommissioned (pos_admin soft-deletes it); its open shift stays.
        $this->till->delete();
        $this->assertSoftDeleted('pos_devices', ['id' => $this->till->id]);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 7)->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $close = $this->p5Push('mdev_p6_handheld', [$this->p5Event('shift.close', ['shift_uuid' => $handheldShift, 'closing_cash_baisas' => 7000,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 9, 'order_uuids' => [], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$handheldShift)->toString())])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $close['status'], json_encode($close));
        $this->assertSame([7000, 0, 1], [$close['result']['expected_cash_baisas'], $close['result']['variance_baisas'],
            $close['result']['summary']['order_count']]);
    }

    // ---- F-17 — the holder's late pay is not fenced a second time ----

    public function test_f17_at_a_geofenced_branch_the_holders_late_pay_without_gps_is_paid_once(): void
    {
        Branch::query()->whereKey(10)->update(['latitude' => '23.5800000', 'longitude' => '58.3800000', 'geofence_radius_m' => 100]);
        // The fixture's devices were paired at a branch without a location ("any"); this one follows the fence.
        $this->handheld->forceFill(['location_mode' => 'branch', 'location_mode_since' => now()->subDay()])->save();
        $inside = ['lat' => 23.58, 'lng' => 58.38];
        // The fence is on: an ordinary pay without a GPS fix is refused.
        $plain = $this->p6Submit()->assertCreated()->json('data');
        $this->assertStringContainsString('GPS fix is required',
            (string) $this->p6PayCash($this->handheld, $plain['order_uuid'], 2000)->json('data.results.0.result.error'));

        $order = $this->p6Submit()->assertCreated()->json('data');
        $this->p6Staff($this->handheld, 9, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $order['order_uuid'], 'gps' => $inside])
            ->assertOk();
        $this->travel(6)->minutes();
        Artisan::call('qr:sweep-stale-charges');

        $this->p6PayCash($this->handheld, $order['order_uuid'], 2000)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(Order::STATUS_PAID, $this->p6Order($order['order_uuid'])->status);
        $this->assertSame('failed', $this->p6PayCash($this->handheld, $order['order_uuid'], 2000)->json('data.results.0.status'));
        $this->assertSame(1, Payment::query()->where('order_id', $this->p6Order($order['order_uuid'])->id)->count());
    }
}
