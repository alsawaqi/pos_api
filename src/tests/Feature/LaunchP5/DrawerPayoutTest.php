<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F6, review M4) — a pay-out belongs to a drawer.
 * `expense.log` with paid_from_drawer: true carries `shift_uuid` (the
 * device's open drawer shift); the server stores pos_expenses.shift_id and
 * the close takes the pay-out off THAT shift, whoever logged it. Without
 * shift_uuid (an old build) today's rule applies: the logger's own shift.
 */
class DrawerPayoutTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_a');
        $this->p5Device('mdev_b');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(9, 'supervisor', '900009');
        $this->p5Staff(10, 'cashier', '100010');
    }

    private function open(string $device, int $staff): string
    {
        $uuid = (string) Str::uuid();
        $this->p5Push($device, [$this->p5Event('shift.open', ['uuid' => $uuid, 'staff_id' => $staff, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHours(2)->toIso8601String()], at: now()->subHours(2)->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        return $uuid;
    }

    private function sale(string $device, int $staff): string
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(30)->toIso8601String();
        $create = $this->p5Create($uuid, ['opened_at' => $at, 'staff_id' => $staff]);
        $create['client_timestamp'] = $at;
        $this->p5Push($device, [$create, $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]], ['staff_id' => $staff], $at)])
            ->assertOk()->assertJsonPath('data.results.1.status', 'processed');

        return $uuid;
    }

    /** @param array<string, mixed> $extra */
    private function payout(string $device, int $staff, array $extra): void
    {
        $this->p5Push($device, [$this->p5Event('expense.log', $extra + ['category' => 'supplies', 'amount_baisas' => 2500,
            'staff_id' => $staff, 'paid_from_drawer' => true, 'auth_v' => 1, 'authorization' => $this->p5Position('payout', $staff),
            'logged_at' => now()->subMinutes(10)->toIso8601String()])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    /** @param list<string> $orders @return array{0: int, 1: int, 2: int} expected, variance, pay-outs */
    private function close(string $device, string $shift, int $staff, int $closing, array $orders): array
    {
        $res = $this->p5Push($device, [$this->p5Event('shift.close', ['shift_uuid' => $shift, 'closing_cash_baisas' => $closing,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => $staff, 'order_uuids' => $orders, 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$shift.':0')->toString())])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');

        return [(int) $res->json('data.results.0.result.expected_cash_baisas'), (int) $res->json('data.results.0.result.variance_baisas'),
            (int) $res->json('data.results.0.result.summary.payouts_baisas')];
    }

    public function test_a_shiftless_supervisors_pay_out_comes_off_the_drawer_it_names(): void
    {
        // Review probe 7: drawer = 5.000 + 10.000 + 10.000 - 2.500 = 22.500.
        $shift = $this->open('mdev_a', 7);
        $sales = [$this->sale('mdev_a', 7), $this->sale('mdev_a', 9)];
        $this->payout('mdev_a', 9, ['shift_uuid' => $shift]);
        $this->assertSame((int) DB::table('pos_shifts')->where('uuid', $shift)->value('id'), (int) DB::table('pos_expenses')->value('shift_id'));

        $this->assertSame([22500, 0, 2500], $this->close('mdev_a', $shift, 7, 22500, $sales));
    }

    public function test_a_pay_out_counts_on_the_shift_it_names_only(): void
    {
        $a = $this->open('mdev_a', 7);
        $b = $this->open('mdev_b', 10);
        // Logged by 7 but taken from 10's drawer.
        $this->payout('mdev_b', 7, ['shift_uuid' => $b]);

        $this->assertSame([5000, 0, 0], $this->close('mdev_a', $a, 7, 5000, []));
        $this->assertSame([2500, 0, 2500], $this->close('mdev_b', $b, 10, 2500, []));
    }

    public function test_a_re_sent_pay_out_never_makes_a_second_expense(): void
    {
        // The handheld journals a drawer pay-out and re-sends it (same
        // client_event_id) until it is answered, e.g. before a shift close.
        $shift = $this->open('mdev_a', 7);
        $event = $this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 2500, 'staff_id' => 7,
            'paid_from_drawer' => true, 'shift_uuid' => $shift, 'auth_v' => 1, 'authorization' => $this->p5Position('payout', 7),
            'logged_at' => now()->subMinutes(10)->toIso8601String()]);
        $first = $this->p5Push('mdev_a', [$event])->assertOk()->assertJsonPath('data.results.0.duplicate', false)
            ->json('data.results.0.result');
        foreach ([1, 2] as $i) {
            $this->p5Push('mdev_a', [$event])->assertOk()->assertJsonPath('data.results.0.duplicate', true)
                ->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result', $first);
        }
        $this->assertSame(1, DB::table('pos_expenses')->count());
        $this->assertSame(1, DB::table('pos_approvals')->where('action', 'payout')->count());
        $this->assertSame([2500, 0, 2500], $this->close('mdev_a', $shift, 7, 2500, []));

        // A late one re-sent after the close is counted once too.
        $late = $this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 1000, 'staff_id' => 7,
            'paid_from_drawer' => true, 'shift_uuid' => $shift, 'auth_v' => 1, 'authorization' => $this->p5Position('payout', 7),
            'logged_at' => now()->subMinutes(5)->toIso8601String()]);
        $this->p5Push('mdev_a', [$late])->assertOk();
        $this->p5Push('mdev_a', [$late])->assertOk()->assertJsonPath('data.results.0.duplicate', true);
        $this->assertSame([2, 1000], [DB::table('pos_expenses')->count(),
            (int) DB::table('pos_shifts')->where('uuid', $shift)->value('late_payouts_baisas')]);
    }

    public function test_without_a_known_shift_today_s_rule_applies(): void
    {
        $shift = $this->open('mdev_a', 7);
        $this->payout('mdev_a', 7, []);
        $this->p5Push('mdev_a', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 1000, 'staff_id' => 7,
            'paid_from_drawer' => true, 'shift_uuid' => (string) Str::uuid(), 'auth_v' => 1, 'authorization' => $this->p5Position('payout', 7),
            'logged_at' => now()->subMinutes(5)->toIso8601String()])])->assertOk()
            ->assertJsonPath('data.results.0.result.integrity_flags', ['payout_shift_unknown']);
        $this->assertSame([null, null], DB::table('pos_expenses')->orderBy('id')->pluck('shift_id')->all());

        $this->assertSame([1500, 0, 3500], $this->close('mdev_a', $shift, 7, 1500, []));
    }
}
