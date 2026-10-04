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
 * LAUNCH-P5 A2 / A3 — the authorization blocks on sync events and the
 * pos_approvals rows (B1, M7, M8).
 *
 * Proof subject per event (the device builds the same canonical):
 *   order.create  subject = the order uuid; discount.manual / comp / gift
 *                 amount = that row's amount_baisas; ref = the block's ref
 *                 ("discount:i", "comp:i", "gift:i")
 *   order.void    subject = the order uuid, no amount
 *   expense.log   no subject (the server makes the expense uuid),
 *                 amount = amount_baisas
 * A paid sale is never rejected over an approval: the verdict is recorded.
 */
class SaleAuthorizationTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->p5Device('mdev_sale');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009', verifier: true);
        DB::table('pos_comp_reasons')->insert(['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'code' => 'staff_meal', 'name' => 'Staff Meal', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A 30 % manual discount on a 10.000 order (cashier's maximum is 10 %). @return array<string, mixed> */
    private function discountOrder(string $uuid, array $extra = []): array
    {
        return array_merge(['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000, 'reason' => 'regular']]], $extra);
    }

    public function test_a_manual_discount_above_the_cashiers_maximum_with_a_valid_offline_approval_is_verified(): void
    {
        $uuid = (string) Str::uuid();
        $block = $this->p5Approval($this->device, 'discount.manual', 8, 7, $uuid, 3000, 'discount:0');

        $res = $this->p5Push('mdev_sale', [$this->p5Create($uuid, $this->discountOrder($uuid), ['auth_v' => 1, 'authorizations' => [$block]])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.authorizations.0.result', 'verified');

        $row = $this->p5Approvals()[0];
        $this->assertSame(['discount.manual', 'verified', 'approval', 'offline', 'order', $uuid, 'discount:0', 7, 8],
            [$row['action'], $row['result'], $row['mode'], $row['method'], $row['subject_type'], $row['subject_uuid'], $row['ref'],
                (int) $row['actor_staff_id'], (int) $row['approver_staff_id']]);
        $this->assertSame('3.000', number_format((float) $row['amount'], 3));
        $this->assertSame((string) $res->json('data.results.0.client_event_id'), $row['client_event_id']);
        $this->assertNotNull($row['verified_at']);
    }

    public function test_the_verdicts_missing_failed_unverifiable_and_position_ok_never_reject_the_sale(): void
    {
        $this->p5Staff(10, 'manager', '100010'); // an approver with no verifier on the server
        $cases = [];
        // No block at all → missing.
        $cases['missing'] = [];
        // A bad proof → failed.
        $bad = $this->p5Approval($this->device, 'discount.manual', 8, 7, null, 3000, 'discount:0');
        $bad['proof'] = str_repeat('ab', 32);
        $cases['failed'] = [$bad];
        // No K on the server → unverifiable.
        $cases['unverifiable'] = [['action' => 'discount.manual', 'ref' => 'discount:0', 'mode' => 'approval', 'actor_staff_id' => 7,
            'approver_staff_id' => 10, 'approved_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'method' => 'offline', 'proof' => str_repeat('cd', 32)]];
        // A position block from a cashier above the maximum → missing.
        $cases['missing_position'] = [$this->p5Position('discount.manual', 7, 'discount:0')];

        foreach ($cases as $expected => $blocks) {
            $uuid = (string) Str::uuid();
            $this->p5Push('mdev_sale', [$this->p5Create($uuid, $this->discountOrder($uuid), ['auth_v' => 1, 'authorizations' => $blocks])])
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
            $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'status' => 'open']);
            $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
            $this->assertSame(str_replace('_position', '', $expected), $row->result, $expected);
        }

        // A supervisor's own 25 % maximum covers 20 %: position_ok, no approver.
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($uuid, ['staff_id' => 9, 'discount_total_baisas' => 2000, 'grand_total_baisas' => 8000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 2000]]], ['auth_v' => 1, 'authorizations' => [$this->p5Position('discount.manual', 9, 'discount:0')]])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
        $this->assertSame(['position_ok', 'position', 9], [$row->result, $row->mode, (int) $row->actor_staff_id]);
        $this->assertNull($row->approver_staff_id);
    }

    public function test_a_discount_within_the_maximum_needs_no_block_and_writes_no_row(): void
    {
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($uuid, ['discount_total_baisas' => 1000, 'grand_total_baisas' => 9000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 1000]]], ['auth_v' => 1])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertSame([], $this->p5Approvals());
    }

    public function test_a_needs_manager_rule_always_needs_an_approver(): void
    {
        DB::table('pos_discounts')->insert(['id' => 5, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'VIP',
            'scope' => 'order', 'amount_type' => 'percent', 'amount' => 5, 'status' => 'active', 'requires_manager_approval' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) Str::uuid();
        // Even a manager's own position is not enough (M7).
        $this->p5Push('mdev_sale', [$this->p5Create($uuid, ['staff_id' => 8, 'discount_total_baisas' => 500, 'grand_total_baisas' => 9500,
            'discounts' => [['name' => 'VIP', 'discount_id' => 5, 'amount_baisas' => 500]]],
            ['auth_v' => 1, 'authorizations' => [$this->p5Position('discount.manual', 8, 'discount:0')]])])->assertOk();

        $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
        $this->assertSame(['missing', 'needs_approval'], [$row->result, $row->reason]);
    }

    public function test_the_comp_approver_is_the_verified_approver_never_the_cashier(): void
    {
        $uuid = (string) Str::uuid();
        $comps = [
            ['comp_reason_id' => 2, 'amount_baisas' => 1000, 'staff_id' => 7],
            ['is_gift' => true, 'amount_baisas' => 500, 'staff_id' => 7],
        ];
        $blocks = [
            $this->p5Approval($this->device, 'comp', 8, 7, $uuid, 1000, 'comp:0'),
            // The gift block arrives without the ref the server derives: matched by action.
            $this->p5Approval($this->device, 'gift', 9, 7, $uuid, 500, 'gift:7'),
        ];
        $this->p5Push('mdev_sale', [$this->p5Create($uuid, ['comp_total_baisas' => 1500, 'grand_total_baisas' => 8500, 'comps' => $comps],
            ['auth_v' => 1, 'authorizations' => $blocks])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $rows = DB::table('pos_order_comps')->orderBy('id')->get();
        $this->assertSame(8, (int) $rows[0]->approved_by_pos_staff_id);
        // The supervisor holds no approvals.give: failed, so no approver is recorded.
        $this->assertNull($rows[1]->approved_by_pos_staff_id);
        $verdicts = DB::table('pos_approvals')->orderBy('id')->get(['action', 'result', 'reason'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['action' => 'comp', 'result' => 'verified', 'reason' => null],
            ['action' => 'gift', 'result' => 'failed', 'reason' => 'approver_not_allowed']], $verdicts);

        // A comp with no block from a P5 build: missing, no approver (B1).
        $uuid2 = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($uuid2, ['comp_total_baisas' => 1000, 'grand_total_baisas' => 9000,
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => 1000, 'staff_id' => 7]]], ['auth_v' => 1])])->assertOk();
        $this->assertNull(DB::table('pos_order_comps')->orderByDesc('id')->value('approved_by_pos_staff_id'));
        $this->assertSame('missing', DB::table('pos_approvals')->where('subject_uuid', $uuid2)->value('result'));
    }

    public function test_an_old_build_keeps_todays_behaviour_and_writes_legacy_rows(): void
    {
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($uuid, $this->discountOrder($uuid, ['comp_total_baisas' => 1000, 'grand_total_baisas' => 6000,
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => 1000, 'staff_id' => 7]]]))])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertSame(['legacy', 'legacy'], DB::table('pos_approvals')->orderBy('id')->pluck('result')->all());
        $this->assertSame(['discount.manual', 'comp'], DB::table('pos_approvals')->orderBy('id')->pluck('action')->all());
        // Today's comp attribution for an old build stays.
        $this->assertSame(7, (int) DB::table('pos_order_comps')->value('approved_by_pos_staff_id'));
    }

    public function test_a_void_records_the_voider_and_the_checked_approver(): void
    {
        $paid = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($paid), $this->p5Pay($paid, [['method' => 'cash', 'amount_baisas' => 10000]])])->assertOk();

        $block = $this->p5Approval($this->device, 'order.void_paid', 8, 7, $paid);
        $this->p5Push('mdev_sale', [$this->p5Event('order.void', ['order_uuid' => $paid, 'staff_id' => 7, 'auth_v' => 1, 'authorization' => $block])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $order = DB::table('pos_orders')->where('uuid', $paid)->first();
        $this->assertSame(['void', 7, 8], [$order->status, (int) $order->voided_by_staff_id, (int) $order->void_approved_by_staff_id]);
        $this->assertSame('verified', DB::table('pos_approvals')->where('action', 'order.void_paid')->value('result'));

        // An unpaid order voided by a cashier without a block: voided (never
        // refused), recorded missing, no approver.
        $open = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($open)])->assertOk();
        $this->p5Push('mdev_sale', [$this->p5Event('order.void', ['order_uuid' => $open, 'staff_id' => 7, 'auth_v' => 1])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $order = DB::table('pos_orders')->where('uuid', $open)->first();
        $this->assertSame(['void', 7], [$order->status, (int) $order->voided_by_staff_id]);
        $this->assertNull($order->void_approved_by_staff_id);
        $this->assertSame('missing', DB::table('pos_approvals')->where('action', 'order.void_unpaid')->value('result'));

        // A supervisor voids an unpaid order on their own tick: position_ok,
        // the approver is the supervisor.
        $own = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($own)])->assertOk();
        $this->p5Push('mdev_sale', [$this->p5Event('order.void', ['order_uuid' => $own, 'staff_id' => 9, 'auth_v' => 1,
            'authorization' => $this->p5Position('order.void_unpaid', 9)])])->assertOk();
        $this->assertSame(9, (int) DB::table('pos_orders')->where('uuid', $own)->value('void_approved_by_staff_id'));
    }

    public function test_one_approval_verifies_for_one_order_only(): void
    {
        $first = (string) Str::uuid();
        $block = $this->p5Approval($this->device, 'order.void_paid', 8, 7, null);
        foreach ([$first, $second = (string) Str::uuid()] as $uuid) {
            $this->p5Push('mdev_sale', [$this->p5Create($uuid), $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]])])->assertOk();
            $this->p5Push('mdev_sale', [$this->p5Event('order.void', ['order_uuid' => $uuid, 'staff_id' => 7, 'auth_v' => 1, 'authorization' => $block])])
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        }

        $this->assertSame('verified', DB::table('pos_approvals')->where('subject_uuid', $first)->value('result'));
        $replayed = DB::table('pos_approvals')->where('subject_uuid', $second)->sole();
        $this->assertSame(['failed', 'proof_reused'], [$replayed->result, $replayed->reason]);
        $this->assertNull(DB::table('pos_orders')->where('uuid', $second)->value('void_approved_by_staff_id'));
    }

    public function test_pay_records_the_payer_and_a_gift_tender_without_an_approval_is_missing(): void
    {
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_sale', [
            $this->p5Create($uuid, [], ['auth_v' => 1]),
            $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 6000], ['method' => 'gift', 'amount_baisas' => 4000]],
                ['auth_v' => 1, 'staff_id' => 7]),
        ])->assertOk()->assertJsonPath('data.results.1.status', 'processed')
            ->assertJsonPath('data.results.1.result.authorizations.0.result', 'missing');
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'status' => 'paid']);
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['gift', 'tender:1', 'missing', 7], [$row->action, $row->ref, $row->result, (int) $row->actor_staff_id]);

        // A whole-bill gift approved on order.create covers the tender.
        $uuid2 = (string) Str::uuid();
        $gift = $this->p5Approval($this->device, 'gift', 8, 7, $uuid2, 10000, 'tender:0');
        $this->p5Push('mdev_sale', [
            $this->p5Create($uuid2, [], ['auth_v' => 1, 'authorizations' => [$gift]]),
            $this->p5Pay($uuid2, [['method' => 'gift', 'amount_baisas' => 10000]], ['auth_v' => 1, 'staff_id' => 7]),
        ])->assertOk()->assertJsonPath('data.results.1.result.authorizations.0.result', 'at_create');
        $this->assertSame(['verified'], DB::table('pos_approvals')->where('subject_uuid', $uuid2)->pluck('result')->all());

        // A part-gift tender approved on order.pay itself (its amount is known there).
        $uuid4 = (string) Str::uuid();
        $partGift = $this->p5Approval($this->device, 'gift', 8, 7, $uuid4, 4000, 'tender:1');
        $this->p5Push('mdev_sale', [
            $this->p5Create($uuid4, [], ['auth_v' => 1]),
            $this->p5Pay($uuid4, [['method' => 'cash', 'amount_baisas' => 6000], ['method' => 'gift', 'amount_baisas' => 4000]],
                ['auth_v' => 1, 'staff_id' => 7, 'authorizations' => [$partGift]]),
        ])->assertOk()->assertJsonPath('data.results.1.result.authorizations.0.result', 'verified');

        // A payer from another company never refuses the sale; it is flagged.
        $this->p5Staff(70, 'cashier', '777000', overrides: ['company_id' => 200]);
        $uuid3 = (string) Str::uuid();
        $this->p5Push('mdev_sale', [$this->p5Create($uuid3), $this->p5Pay($uuid3, [['method' => 'cash', 'amount_baisas' => 10000]], ['staff_id' => 70])])
            ->assertOk()->assertJsonPath('data.results.1.status', 'processed')
            ->assertJsonPath('data.results.1.result.integrity_flags', ['pay_staff_unknown:70']);
    }

    public function test_a_device_pay_out_is_marked_from_the_drawer_and_its_payout_approval_is_checked(): void
    {
        $block = $this->p5Approval($this->device, 'payout', 8, 7, null, 2500);
        $this->p5Push('mdev_sale', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 2500, 'staff_id' => 7,
            'paid_from_drawer' => true, 'auth_v' => 1, 'authorization' => $block])])
            ->assertOk()->assertJsonPath('data.results.0.result.authorization.result', 'verified');
        $this->assertTrue((bool) DB::table('pos_expenses')->value('paid_from_drawer'));
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['payout', 'expense', 'verified', 8], [$row->action, $row->subject_type, $row->result, (int) $row->approver_staff_id]);

        // A plain expense is not a pay-out and needs nothing.
        $this->p5Push('mdev_sale', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 100, 'staff_id' => 7, 'auth_v' => 1])])->assertOk();
        $this->assertSame(1, DB::table('pos_approvals')->count());
        $this->assertFalse((bool) DB::table('pos_expenses')->orderByDesc('id')->value('paid_from_drawer'));
    }

    public function test_an_approver_must_work_at_the_branch_and_have_been_active_at_the_approval(): void
    {
        $this->p5Staff(11, 'manager', '110011', verifier: true, overrides: ['branch_id' => 11]);
        $this->p5Staff(12, 'manager', '120012', verifier: true, overrides: ['status' => 'suspended']);
        $this->p5Staff(13, 'manager', '130013', verifier: true, overrides: ['status' => 'terminated',
            'terminated_at' => now()->subMinute(), 'deleted_at' => now()->subMinute()]);
        $reasons = [];
        foreach ([11 => 'approver_not_at_branch', 12 => 'approver_inactive', 13 => null] as $approver => $expected) {
            $uuid = (string) Str::uuid();
            $block = $this->p5Approval($this->device, 'discount.manual', $approver, 7, $uuid, 3000, 'discount:0',
                now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s.v\Z'));
            $this->p5Push('mdev_sale', [$this->p5Create($uuid, $this->discountOrder($uuid), ['auth_v' => 1, 'authorizations' => [$block]])])->assertOk();
            $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
            $reasons[$approver] = [$row->result, $row->reason];
        }
        // 13 was terminated AFTER approving offline: still verified.
        $this->assertSame([11 => ['failed', 'approver_not_at_branch'], 12 => ['failed', 'approver_inactive'], 13 => ['verified', null]], $reasons);
    }
}
