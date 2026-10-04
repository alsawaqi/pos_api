<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F8, review M6) — the staff login, the manager-PIN
 * check, the PIN-lock unlock, /device/approvers (every approver's salt,
 * iterations and check), /device/staff-status and the attendance events are
 * for a till (fixed_pos) or a handheld only. A customer tablet or a payment
 * station faces the customer: 403 device_not_attended, and its attendance
 * events are refused for good.
 */
class AttendedDeviceTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function routes(): array
    {
        return [
            ['GET', '/api/v1/device/approvers', []],
            ['GET', '/api/v1/device/staff-status', []],
            ['POST', '/api/v1/auth/pos/login', ['pin' => '700007']],
            ['POST', '/api/v1/device/auth/verify-manager-pin', ['pin' => '800008']],
            ['POST', '/api/v1/device/auth/unlock-pin-lock', ['pin' => '800008']],
        ];
    }

    public function test_a_customer_tablet_and_a_payment_station_get_no_approver_material_and_no_staff_pin_routes(): void
    {
        foreach (['customer_tablet', 'payment_station'] as $i => $type) {
            $this->p5Device('mdev_ct'.$i, extra: ['device_type' => $type]);
            foreach ($this->routes() as [$method, $url, $body]) {
                $this->app['auth']->forgetGuards();
                $res = $this->withToken('mdev_ct'.$i)->json($method, $url, $body);
                $res->assertStatus(403)->assertJsonPath('errors.0.code', 'device_not_attended');
                $this->assertStringNotContainsString('"check"', $res->getContent(), $type.' '.$url);
            }
        }
        $this->assertNull(DB::table('pos_staff')->where('id', 7)->value('last_login_at'));
    }

    public function test_a_till_and_a_handheld_keep_every_staff_route(): void
    {
        foreach (['fixed_pos', 'handheld'] as $i => $type) {
            $this->p5Device('mdev_att'.$i, extra: ['device_type' => $type]);
            foreach ($this->routes() as [$method, $url, $body]) {
                $this->app['auth']->forgetGuards();
                $this->withToken('mdev_att'.$i)->json($method, $url, $body)->assertOk();
            }
            $this->app['auth']->forgetGuards();
            $this->withToken('mdev_att'.$i)->getJson('/api/v1/device/approvers')
                ->assertJsonPath('data.approvers.0.staff_id', 8);
        }
    }

    public function test_attendance_events_from_a_customer_facing_device_are_refused_for_good(): void
    {
        $this->p5Device('mdev_ps', extra: ['device_type' => 'payment_station']);
        $this->p5Device('mdev_hh', extra: ['device_type' => 'handheld']);
        $event = fn (): array => $this->p5Event('staff.clock_in', ['attendance_uuid' => (string) Str::uuid(), 'staff_id' => 7,
            'at' => now()->subHour()->toIso8601String(), 'auth_v' => 1]);

        $this->p5Push('mdev_ps', [$event()])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.code', 'device_not_attended')
            ->assertJsonPath('data.results.0.result.permanent', true);
        $this->assertSame(0, DB::table('pos_staff_attendance')->count());

        $this->p5Push('mdev_hh', [$event()])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.status', 'clocked_in');
    }
}
