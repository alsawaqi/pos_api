<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Support\Staff\AuthorizationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (L3) — device-controlled block values never reach
 * pos_approvals beyond its columns (action varchar(32), ref varchar(64)),
 * where Postgres would fail the insert and wedge the sale and its shift
 * close. A block keeps only a known action (an unknown one is ignored), and
 * a ref longer than 64 characters is recorded failed `invalid_block` with
 * the ref cut short. SQLite does not enforce the lengths, so the stored
 * values are asserted.
 */
class BlockBoundsTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_bounds');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
    }

    public function test_a_long_ref_is_recorded_invalid_and_cut_short_and_an_unknown_action_is_ignored(): void
    {
        $uuid = (string) Str::uuid();
        $long = 'discount:0'.str_repeat('x', 70);
        $this->p5Push('mdev_bounds', [$this->p5Create($uuid, ['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000]]], ['auth_v' => 1, 'authorizations' => [
                $this->p5Position('discount.manual', 7, $long),
                $this->p5Position(str_repeat('a', 40), 7, 'discount:0'),
            ]])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $rows = DB::table('pos_approvals')->where('subject_uuid', $uuid)->orderBy('id')->get(['action', 'ref', 'result', 'reason']);
        $this->assertSame([['discount.manual', 'discount:0', 'missing', 'no_authorization'],
            ['discount.manual', mb_substr($long, 0, 64), 'failed', 'invalid_block']],
            $rows->map(fn ($r): array => [$r->action, $r->ref, $r->result, $r->reason])->all());
        $this->assertSame(64, mb_strlen((string) $rows[1]->ref));
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(32, mb_strlen((string) $row->action));
        }
    }

    public function test_a_single_block_event_with_a_long_ref_is_failed_invalid_block(): void
    {
        $paid = (string) Str::uuid();
        $this->p5Push('mdev_bounds', [$this->p5Create($paid), $this->p5Pay($paid, [['method' => 'cash', 'amount_baisas' => 10000]])])->assertOk();
        $this->p5Push('mdev_bounds', [$this->p5Event('order.void', ['order_uuid' => $paid, 'staff_id' => 7, 'auth_v' => 1,
            'authorization' => $this->p5Position('order.void_paid', 7, str_repeat('r', 100))])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $row = DB::table('pos_approvals')->where('subject_uuid', $paid)->sole();
        $this->assertSame(['failed', 'invalid_block', 64], [$row->result, $row->reason, mb_strlen((string) $row->ref)]);
    }

    public function test_the_normalised_block_bounds_action_and_ref(): void
    {
        $block = AuthorizationGate::block(['action' => 'not.a.gated.action', 'ref' => str_repeat('é', 65), 'mode' => 'position']);
        $this->assertSame([null, 64, true], [$block['action'], mb_strlen((string) $block['ref']), $block['invalid']]);
        $this->assertFalse(AuthorizationGate::block(['action' => 'comp', 'ref' => 'comp:0', 'mode' => 'position'])['invalid']);
    }
}
