<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F5, review M3) — `order.void` checks
 * order.void_paid or order.void_unpaid from the order's paid state on the
 * server at processing time. A block naming the other void action is checked
 * against the stricter one (order.void_paid); if that fails the row is
 * failed `action_mismatch` and nobody is recorded as the approver. The void
 * itself always goes through (an offline event).
 */
class VoidActionTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->p5Device('mdev_void');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
    }

    private function order(bool $paid): string
    {
        $uuid = (string) Str::uuid();
        $events = [$this->p5Create($uuid)];
        if ($paid) {
            $events[] = $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]]);
        }
        $this->p5Push('mdev_void', $events)->assertOk();

        return $uuid;
    }

    private function void(string $uuid, int $staff, array $block): void
    {
        $this->p5Push('mdev_void', [$this->p5Event('order.void', ['order_uuid' => $uuid, 'staff_id' => $staff, 'auth_v' => 1,
            'authorization' => $block])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: ?int} */
    private function row(string $uuid): array
    {
        $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
        $order = DB::table('pos_orders')->where('uuid', $uuid)->first();
        $this->assertSame('void', $order->status);

        return [$row->action, $row->result, $row->reason, $order->void_approved_by_staff_id === null ? null : (int) $order->void_approved_by_staff_id];
    }

    public function test_a_paid_void_under_a_void_unpaid_block_is_checked_as_a_paid_void(): void
    {
        // Review probe 3: the supervisor holds void_unpaid but not void_paid.
        $paid = $this->order(true);
        $this->void($paid, 9, $this->p5Position('order.void_unpaid', 9));
        $this->assertSame(['order.void_paid', 'failed', 'action_mismatch', null], $this->row($paid));

        // An approval made for an unpaid void does not cover a paid one either.
        $paid2 = $this->order(true);
        $this->void($paid2, 7, $this->p5Approval($this->device, 'order.void_unpaid', 8, 7, $paid2));
        $this->assertSame(['order.void_paid', 'failed', 'action_mismatch', null], $this->row($paid2));
    }

    public function test_an_unpaid_void_under_a_void_paid_block_holds_only_if_the_stricter_check_does(): void
    {
        $open = $this->order(false);
        $this->void($open, 7, $this->p5Approval($this->device, 'order.void_paid', 8, 7, $open));
        $this->assertSame(['order.void_paid', 'verified', null, 8], $this->row($open));

        $open2 = $this->order(false);
        $this->void($open2, 9, $this->p5Position('order.void_paid', 9));
        $this->assertSame(['order.void_paid', 'failed', 'action_mismatch', null], $this->row($open2));
    }

    public function test_a_block_naming_the_servers_action_is_checked_as_before(): void
    {
        $open = $this->order(false);
        $this->void($open, 9, $this->p5Position('order.void_unpaid', 9));
        $this->assertSame(['order.void_unpaid', 'position_ok', null, 9], $this->row($open));

        $paid = $this->order(true);
        $this->void($paid, 9, $this->p5Position('order.void_paid', 9));
        $this->assertSame(['order.void_paid', 'missing', 'not_ticked', null], $this->row($paid));
    }
}
