<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F1, review H1) — the actor of a position block is
 * bound to a login on that device by a signed staff token:
 *
 *   staff_token = base64url(json{v:1, d:<device_id>, s:<staff_id>, iat:<unix>})
 *                 "." base64url(HMAC-SHA256(key, first part)),
 *   key = HMAC-SHA256("pos-staff-token-v1", config app.key)
 *
 *  - login returns it (data.staff_token, also data.staff.staff_token);
 *  - a P5 sync event carries it as `staff_token`; a position block counts
 *    for the token's staff member only when that is the event's own staff
 *    member (the block's actor_staff_id is ignored); no valid token → failed
 *    `actor_unverified` + an integrity flag, the sale is still accepted; an
 *    actor not active at the event time → failed `actor_inactive`;
 *  - approval blocks keep their own proof (no token needed);
 *  - the online P5 table operations, sold-out and the shift read need the
 *    X-Staff-Token header: 403 staff_unverified {reason} without it.
 */
class StaffTokenTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->device = $this->p5Device('mdev_tok');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
        DB::table('pos_comp_reasons')->insert(['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'code' => 'staff_meal', 'name' => 'Staff Meal', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_login_returns_a_signed_staff_token_bound_to_the_device_and_the_person(): void
    {
        $before = time();
        $res = $this->withToken('mdev_tok')->postJson('/api/v1/auth/pos/login', ['pin' => '700007'])->assertOk();
        $token = (string) $res->json('data.staff_token');
        $this->assertSame($token, $res->json('data.staff.staff_token'));

        [$body, $signature] = explode('.', $token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $body.$signature);
        $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame(['v', 'd', 's', 'iat'], array_keys($claims));
        $this->assertSame([1, (int) $this->device->id, 7], [$claims['v'], $claims['d'], $claims['s']]);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        // The formula, rebuilt independently, gives the same token.
        $this->assertSame($this->p5StaffToken($this->device, 7, $claims['iat']), $token);
    }

    public function test_a_position_block_counts_for_the_tokens_staff_member_never_the_blocks_actor(): void
    {
        // Review probe 1: the cashier names the manager as actor.
        $uuid = (string) Str::uuid();
        $order = ['staff_id' => 7, 'discount_total_baisas' => 5000, 'comp_total_baisas' => 1000, 'grand_total_baisas' => 4000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 5000]],
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => 1000, 'staff_id' => 7]]];
        $this->p5Push('mdev_tok', [$this->p5Create($uuid, $order, ['auth_v' => 1, 'authorizations' => [
            $this->p5Position('discount.manual', 8, 'discount:0'),
            $this->p5Position('comp', 8, 'comp:0'),
        ]])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $rows = DB::table('pos_approvals')->orderBy('id')->get(['action', 'result', 'reason', 'actor_staff_id'])
            ->map(fn ($r): array => [$r->action, $r->result, $r->reason, (int) $r->actor_staff_id])->all();
        $this->assertSame([['discount.manual', 'missing', 'above_max', 7], ['comp', 'missing', 'not_ticked', 7]], $rows);
        $this->assertNull(DB::table('pos_order_comps')->value('approved_by_pos_staff_id'));
    }

    public function test_a_p5_event_without_a_valid_token_gets_actor_unverified_and_is_still_accepted(): void
    {
        $other = $this->p5Device('mdev_other');
        $cases = [
            'missing' => null,
            'tampered' => substr($this->p5StaffToken($this->device, 9), 0, -2).'AA',
            'other device' => $this->p5StaffToken($other, 9),
            'other staff' => $this->p5StaffToken($this->device, 8),
            'other app key' => (function (): string {
                $key = config('app.key');
                config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
                $token = $this->p5StaffToken($this->device, 9);
                config(['app.key' => $key]);

                return $token;
            })(),
        ];
        foreach ($cases as $case => $token) {
            $uuid = (string) Str::uuid();
            $this->p5Push('mdev_tok', [$this->p5Create($uuid, ['staff_id' => 9, 'discount_total_baisas' => 2000, 'grand_total_baisas' => 8000,
                'discounts' => [['name' => 'Manual', 'amount_baisas' => 2000]]],
                ['auth_v' => 1, 'staff_token' => $token, 'authorizations' => [$this->p5Position('discount.manual', 9, 'discount:0')]])])
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
                ->assertJsonPath('data.results.0.result.integrity_flags', ['actor_unverified:9']);
            $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
            $this->assertSame(['failed', 'actor_unverified', 9], [$row->result, $row->reason, (int) $row->actor_staff_id], $case);
        }

        // The supervisor's own token: their 25 % maximum covers 20 %.
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_tok', [$this->p5Create($uuid, ['staff_id' => 9, 'discount_total_baisas' => 2000, 'grand_total_baisas' => 8000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 2000]]],
            ['auth_v' => 1, 'authorizations' => [$this->p5Position('discount.manual', 9, 'discount:0')]])])->assertOk();
        $this->assertSame('position_ok', DB::table('pos_approvals')->where('subject_uuid', $uuid)->value('result'));

        // A void and a pay-out are bound the same way.
        $paid = (string) Str::uuid();
        $this->p5Push('mdev_tok', [$this->p5Create($paid), $this->p5Pay($paid, [['method' => 'cash', 'amount_baisas' => 10000]])])->assertOk();
        $this->p5Push('mdev_tok', [$this->p5Event('order.void', ['order_uuid' => $paid, 'staff_id' => 7, 'auth_v' => 1,
            'staff_token' => $this->p5StaffToken($this->device, 8), 'authorization' => $this->p5Position('order.void_paid', 8)])])->assertOk();
        $void = DB::table('pos_approvals')->where('subject_uuid', $paid)->sole();
        $this->assertSame(['failed', 'actor_unverified', 7], [$void->result, $void->reason, (int) $void->actor_staff_id]);
        $this->assertNull(DB::table('pos_orders')->where('uuid', $paid)->value('void_approved_by_staff_id'));
    }

    public function test_a_suspended_actor_is_never_position_ok(): void
    {
        // Review probe 9: a suspended manager's own (old) token.
        $this->p5Staff(12, 'manager', '120012', overrides: ['status' => 'suspended']);
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_tok', [$this->p5Create($uuid, ['staff_id' => 12, 'discount_total_baisas' => 5000, 'grand_total_baisas' => 5000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 5000]]],
            ['auth_v' => 1, 'authorizations' => [$this->p5Position('discount.manual', 12, 'discount:0')]])])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['failed', 'actor_inactive', 12], [$row->result, $row->reason, (int) $row->actor_staff_id]);
    }

    public function test_an_approval_block_needs_no_token(): void
    {
        $uuid = (string) Str::uuid();
        $block = $this->p5Approval($this->device, 'discount.manual', 8, 7, $uuid, 3000, 'discount:0');
        $this->p5Push('mdev_tok', [$this->p5Create($uuid, ['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000]]], ['auth_v' => 1, 'staff_token' => null, 'authorizations' => [$block]])])
            ->assertOk()->assertJsonPath('data.results.0.result.authorizations.0.result', 'verified');
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['verified', 7, 8], [$row->result, (int) $row->actor_staff_id, (int) $row->approver_staff_id]);
    }

    public function test_the_online_p5_endpoints_need_the_staff_token_header(): void
    {
        $device = $this->seatingDevice();
        $other = $this->seatingDevice();
        $this->p5Staff(12, 'manager', '120012', overrides: ['status' => 'suspended']);
        $seat = $this->seatingRow($this->seatingTable('P5 table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 9];
        $round = fn (array $extra = []): array => $prefix + $extra + ['client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $product->id, 'qty' => 2]]];
        $url = '/api/v1/device/tables/'.$seat->uuid.'/round';

        $cases = [
            'token_missing' => '',
            'token_invalid' => 'not.a-token',
            'token_other_device' => $this->p5StaffToken($other, 9),
            'token_other_staff' => $this->p5StaffToken($device, 7),
        ];
        foreach ($cases as $reason => $token) {
            $this->p5Online($device, 'POST', $url, $round(['auth_v' => 1]), $token)
                ->assertStatus(403)->assertJsonPath('errors.0.code', 'staff_unverified')->assertJsonPath('data.reason', $reason);
        }
        $this->p5Online($device, 'POST', $url, array_replace($round(['auth_v' => 1]), ['staff_id' => 12]))
            ->assertStatus(403)->assertJsonPath('data.reason', 'staff_inactive');
        $this->assertSame(0, DB::table('pos_qr_order_rounds')->count());

        // The person's own token passes; an old build (no auth_v) needs none.
        $this->p5Online($device, 'POST', $url, $round(['auth_v' => 1]))->assertOk()->assertJsonPath('data.outcome', 'appended');
        $this->p5Online($device, 'POST', $url, $round())->assertOk()->assertJsonPath('data.outcome', 'appended');

        // A position block on an online table action is the token's person.
        $cancel = $prefix + ['auth_v' => 1, 'client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id,
            'qty' => 1, 'prepared' => false, 'reason' => 'x', 'cancelled_at' => now()->toIso8601String()];
        $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/cancel-line',
            array_replace($cancel, ['staff_id' => 7, 'authorization' => $this->p5Position('table.cancel_line', 9)]))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');

        // Sold-out and the shift read.
        $soldOut = '/api/v1/device/products/'.$product->id.'/sold-out';
        $this->p5Online($device, 'POST', $soldOut, ['sold_out' => true, 'staff_id' => 9, 'auth_v' => 1,
            'authorization' => $this->p5Position('sold_out.toggle', 9)], '')
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'staff_unverified')->assertJsonPath('data.reason', 'token_missing');
        $this->p5Online($device, 'POST', $soldOut, ['sold_out' => true, 'staff_id' => 9, 'auth_v' => 1,
            'authorization' => $this->p5Position('sold_out.toggle', 9)])->assertOk()->assertJsonPath('data.authorization.result', 'position_ok');
        $this->p5Online($device, 'GET', '/api/v1/device/shift/current?staff_id=9&auth_v=1', [], '')
            ->assertStatus(403)->assertJsonPath('data.reason', 'token_missing');
        $this->p5Online($device, 'GET', '/api/v1/device/shift/current?staff_id=9&auth_v=1', [], $this->p5StaffToken($device, 9))
            ->assertOk()->assertJsonPath('data.shift', null);
    }
}
