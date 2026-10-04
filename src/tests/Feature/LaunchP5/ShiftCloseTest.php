<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A5 — shift close (owner decisions 3 and 4, B2/M4).
 *
 * A P5 close: { shift_uuid, closing_cash_baisas, closed_at, closed_by_staff_id,
 * order_uuids, authorization?, auth_v: 1 } under a FIXED client_event_id per
 * shift (UUID v5 of "shift-close:" + shift uuid).
 */
class ShiftCloseTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_sc');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
        $this->p5Staff(10, 'cashier', '100010');
        $this->shift = (string) Str::uuid();
        $this->p5Push('mdev_sc', [$this->p5Event('shift.open', ['uuid' => $this->shift, 'staff_id' => 7, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHours(2)->toIso8601String()], at: now()->subHours(2)->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    /** A paid cash sale of 10.000 by staff 7, $minutesAgo before now. */
    private function sale(int $minutesAgo = 30): string
    {
        $uuid = (string) Str::uuid();
        $at = now()->subMinutes($minutesAgo)->toIso8601String();
        $create = $this->p5Create($uuid, ['opened_at' => $at]);
        $create['client_timestamp'] = $at;
        $this->p5Push('mdev_sc', [$create, $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]], [], $at)])
            ->assertOk()->assertJsonPath('data.results.1.status', 'processed');

        return $uuid;
    }

    /** @return array<string, mixed> */
    private function close(array $orderUuids, array $extra = [], int $closing = 15000): array
    {
        return $this->p5Event('shift.close', $extra + ['shift_uuid' => $this->shift, 'closing_cash_baisas' => $closing,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 7, 'order_uuids' => $orderUuids, 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$this->shift)->toString());
    }

    public function test_a_close_waits_for_every_listed_paid_sale_then_succeeds_under_the_same_id(): void
    {
        $sent = $this->sale();
        $queued = (string) Str::uuid();
        $close = $this->close([$sent, $queued]);

        $this->p5Push('mdev_sc', [$close])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.code', 'unsynced_sales')
            ->assertJsonPath('data.results.0.result.missing', [$queued]);
        $this->assertSame('open', DB::table('pos_shifts')->where('uuid', $this->shift)->value('status'));
        $this->assertArrayNotHasKey('permanent', DB::table('pos_sync_events')->where('event_type', 'shift.close')->get()
            ->map(fn ($r) => json_decode($r->result_json, true))->first());

        // The queued sale arrives (paid); the same close then settles.
        $at = now()->subMinutes(20)->toIso8601String();
        $create = $this->p5Create($queued, ['opened_at' => $at]);
        $this->p5Push('mdev_sc', [$create, $this->p5Pay($queued, [['method' => 'cash', 'amount_baisas' => 10000]], [], $at)])->assertOk();
        $res = $this->p5Push('mdev_sc', [$close])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.expected_cash_baisas', 25000);

        $shift = DB::table('pos_shifts')->where('uuid', $this->shift)->first();
        $this->assertSame(['closed', 7, false], [$shift->status, (int) $shift->closed_by_staff_id, (bool) $shift->needs_review]);
        $this->assertSame((int) DB::table('pos_devices')->value('id'), (int) $shift->close_device_id);
        $this->assertSame(2, $res->json('data.results.0.result.summary.order_count'));
    }

    public function test_a_sale_in_permanent_sync_failure_does_not_block_and_is_named_for_review(): void
    {
        $sent = $this->sale();
        $broken = (string) Str::uuid();
        // This device's order.create for it failed for good (a product of no tenant).
        $create = $this->p5Create($broken, ['lines' => [['product_id' => 999, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]],
            'subtotal_baisas' => 1000, 'grand_total_baisas' => 1000]);
        $this->p5Push('mdev_sc', [$create])->assertOk()->assertJsonPath('data.results.0.status', 'failed');

        $this->p5Push('mdev_sc', [$this->close([$sent, $broken])])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.needs_review', true)
            ->assertJsonPath('data.results.0.result.review_order_uuids', [$broken]);
        $shift = DB::table('pos_shifts')->where('uuid', $this->shift)->first();
        $this->assertTrue((bool) $shift->needs_review);
        $this->assertStringContainsString($broken, (string) $shift->note);
    }

    public function test_closing_another_cashiers_drawer_needs_the_tick_or_an_approval(): void
    {
        $this->p5Push('mdev_sc', [$this->close([], ['closed_by_staff_id' => 10])])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.code', 'approval_required');
        $this->assertSame('open', DB::table('pos_shifts')->where('uuid', $this->shift)->value('status'));

        // The same close, retried with a manager's approval: the device's new
        // payload replaces the failed one under the fixed id.
        $approved = $this->close([], ['closed_by_staff_id' => 10, 'authorization' => $this->p5Approval(
            Device::query()->firstOrFail(), 'shift.close_other', 8, 10, $this->shift)], 14000);
        $this->p5Push('mdev_sc', [$approved])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.authorization.result', 'verified');
        $shift = DB::table('pos_shifts')->where('uuid', $this->shift)->first();
        $this->assertSame([10, '14.000'], [(int) $shift->closed_by_staff_id, number_format((float) $shift->closing_cash, 3)]);
    }

    public function test_a_supervisor_closes_another_drawer_on_their_own_tick(): void
    {
        $this->p5Push('mdev_sc', [$this->close([], ['closed_by_staff_id' => 9, 'authorization' => $this->p5Position('shift.close_other', 9)])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.authorization.result', 'position_ok');
        $this->assertSame(9, (int) DB::table('pos_shifts')->value('closed_by_staff_id'));
    }

    public function test_drawer_pay_outs_lower_expected_cash_and_show_on_the_z(): void
    {
        $sent = $this->sale();
        $this->p5Push('mdev_sc', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 2500, 'staff_id' => 7,
            'paid_from_drawer' => true, 'logged_at' => now()->subMinutes(10)->toIso8601String()])])->assertOk();
        // An expense that is not a pay-out, and another cashier's pay-out, do not count.
        $this->p5Push('mdev_sc', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 700, 'staff_id' => 7,
            'logged_at' => now()->subMinutes(10)->toIso8601String()])])->assertOk();
        $this->p5Push('mdev_sc', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 900, 'staff_id' => 10,
            'paid_from_drawer' => true, 'logged_at' => now()->subMinutes(10)->toIso8601String()])])->assertOk();

        $this->p5Push('mdev_sc', [$this->close([$sent], [], 12500)])->assertOk()
            ->assertJsonPath('data.results.0.result.expected_cash_baisas', 5000 + 10000 - 2500)
            ->assertJsonPath('data.results.0.result.variance_baisas', 0)
            ->assertJsonPath('data.results.0.result.summary.payouts_baisas', 2500);
        $this->assertSame(2500, (int) DB::table('pos_shifts')->value('payouts_baisas'));
    }

    public function test_cash_landing_in_a_closed_shift_window_is_a_late_sale_and_the_z_stays(): void
    {
        $this->p5Push('mdev_sc', [$this->close([])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $expected = DB::table('pos_shifts')->value('expected_cash');

        $late = (string) Str::uuid();
        $at = now()->subMinutes(15)->toIso8601String();
        $create = $this->p5Create($late, ['opened_at' => $at]);
        $create['client_timestamp'] = $at;
        $this->p5Push('mdev_sc', [$create, $this->p5Pay($late, [['method' => 'cash', 'amount_baisas' => 10000]], [], $at)])->assertOk()
            ->assertJsonPath('data.results.1.status', 'processed')
            ->assertJsonPath('data.results.1.result.late_for_shift', $this->shift);

        $shift = DB::table('pos_shifts')->first();
        $this->assertSame([10000, true, $expected], [(int) $shift->late_sales_baisas, (bool) $shift->needs_review, $shift->expected_cash]);
        $this->assertStringContainsString($late, (string) $shift->note);
    }

    public function test_a_repeated_close_with_the_fixed_id_returns_the_original_z(): void
    {
        $sent = $this->sale();
        $first = $this->close([$sent]);
        $original = $this->p5Push('mdev_sc', [$first])->assertOk()->json('data.results.0.result');
        $this->assertSame('closed', $original['status']);

        // The device rebuilt the close (a new tap, a new time and count).
        $again = $this->close([$sent], ['closed_at' => now()->addMinute()->toIso8601String()], 99000);
        $this->assertSame($first['client_event_id'], $again['client_event_id']);
        $this->p5Push('mdev_sc', [$again])->assertOk()
            ->assertJsonPath('data.results.0.duplicate', true)
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result', $original);
        $this->assertSame('15.000', number_format((float) DB::table('pos_shifts')->value('closing_cash'), 3));
    }
}
