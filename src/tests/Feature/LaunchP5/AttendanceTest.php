<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A7 — staff.clock_in / staff.clock_out { attendance_uuid,
 * staff_id, at } through the outbox, and the login's attendance state.
 */
class AttendanceTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_att');
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'kitchen', '800008');
    }

    /** @return array<string, mixed> */
    private function clock(string $type, int $staffId, string $uuid, string $at): array
    {
        return $this->p5Event($type, ['attendance_uuid' => $uuid, 'staff_id' => $staffId, 'at' => $at, 'auth_v' => 1]);
    }

    public function test_one_open_attendance_per_person_and_a_clock_out_closes_it(): void
    {
        $in = (string) Str::uuid();
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 7, $in, now()->subHours(3)->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.status', 'clocked_in')
            ->assertJsonPath('data.results.0.result.attendance_uuid', $in);

        // A second clock-in (another device, another uuid) returns the open one.
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 7, (string) Str::uuid(), now()->subHour()->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.result.status', 'already_open')
            ->assertJsonPath('data.results.0.result.attendance_uuid', $in);
        // The same uuid again (a new event for it) is idempotent.
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 7, $in, now()->subHours(3)->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.result.status', 'already_recorded');
        $this->assertSame(1, DB::table('pos_staff_attendance')->count());

        $this->p5Push('mdev_att', [$this->clock('staff.clock_out', 7, $in, now()->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.result.status', 'clocked_out');
        $row = DB::table('pos_staff_attendance')->sole();
        $this->assertSame([100, 10, 7, 'device'], [(int) $row->company_id, (int) $row->branch_id, (int) $row->staff_id, $row->source]);
        $this->assertNotNull($row->clock_out_at);
        $this->assertSame((int) DB::table('pos_devices')->value('id'), (int) $row->device_id);
    }

    public function test_a_clock_out_with_nothing_open_creates_a_flagged_row(): void
    {
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_att', [$this->clock('staff.clock_out', 8, $uuid, now()->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.result.status', 'flagged')
            ->assertJsonPath('data.results.0.result.flags.no_clock_in', true);
        $row = DB::table('pos_staff_attendance')->sole();
        $this->assertSame($row->clock_in_at, $row->clock_out_at);
        $this->assertSame(['no_clock_in' => true], json_decode($row->flags, true));
    }

    public function test_an_open_attendance_older_than_16_hours_is_flagged_no_clock_out(): void
    {
        $old = (string) Str::uuid();
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 7, $old, now()->subHours(20)->toIso8601String())])->assertOk();

        // The next day's clock-in is a new attendance; yesterday's is flagged.
        $new = (string) Str::uuid();
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 7, $new, now()->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.result.status', 'clocked_in')
            ->assertJsonPath('data.results.0.result.attendance_uuid', $new);
        $this->assertSame(['no_clock_out' => true], json_decode(DB::table('pos_staff_attendance')->where('uuid', $old)->value('flags'), true));

        // The login reports the open attendance (today's).
        $this->withToken('mdev_att')->postJson('/api/v1/auth/pos/login', ['pin' => '700007'])->assertOk()
            ->assertJsonPath('data.attendance.open', true)
            ->assertJsonPath('data.attendance.attendance_uuid', $new);
    }

    public function test_a_clock_event_for_another_company_staff_member_fails(): void
    {
        $this->p5Staff(70, 'cashier', '707070', overrides: ['company_id' => 200]);
        $this->p5Push('mdev_att', [$this->clock('staff.clock_in', 70, (string) Str::uuid(), now()->toIso8601String())])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame(0, DB::table('pos_staff_attendance')->count());
    }
}
