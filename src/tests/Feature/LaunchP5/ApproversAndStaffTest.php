<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A3 / A4 / A6 — approvers, the lazy offline verifier, staff at
 * several branches and the staff-status poll.
 *
 *  - verify-manager-pin: branch-limited through pos_staff_branches, needs
 *    approvals.give, lazily makes the verifier and returns {salt, iterations,
 *    check} (never K);
 *  - GET /device/approvers: the branch's approvers with their verifier
 *    material, nulls when none yet;
 *  - a successful login lazily makes the verifier, works at any branch of the
 *    pivot, and returns branch_ids and the attendance state;
 *  - GET /device/staff-status: the ids of the staff still active here.
 */
class ApproversAndStaffTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private function pivot(int $staffId, int $branchId, int $company = 100): void
    {
        DB::table('pos_staff_branches')->insert(['company_id' => $company, 'staff_id' => $staffId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_verify_manager_pin_makes_the_missing_verifier_and_returns_it_without_k(): void
    {
        $this->p5Device('mdev_ap');
        $this->p5Staff(8, 'manager', '481516');
        config(['pos.approver_kdf_iterations' => 1500]);

        $res = $this->withToken('mdev_ap')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '481516'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('staff.id', 8)->assertJsonPath('staff.staff_id', 8)
            ->assertJsonPath('staff.iterations', 1500);

        $row = DB::table('pos_staff')->find(8);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->pin_offline_key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row->pin_offline_salt);
        $this->assertSame(1500, (int) $row->pin_offline_iterations);
        $k = hash_pbkdf2('sha256', '481516', (string) hex2bin($row->pin_offline_salt), 1500, 32, true);
        $this->assertSame(bin2hex($k), $row->pin_offline_key);
        $this->assertSame($row->pin_offline_salt, $res->json('staff.salt'));
        $this->assertSame(hash('sha256', 'mithqal-approver-check-v1'.$k), $res->json('staff.check'));
        $this->assertStringNotContainsString($row->pin_offline_key, $res->getContent());
        $this->assertStringNotContainsString('pin_offline', $res->getContent());

        // A second check never replaces an existing verifier.
        $this->withToken('mdev_ap')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '481516'])->assertOk();
        $this->assertSame($row->pin_offline_key, DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));
    }

    public function test_only_staff_holding_approvals_give_at_this_branch_approve(): void
    {
        $this->p5Device('mdev_ap2');
        $this->p5Staff(8, 'manager', '100001');
        $this->p5Staff(9, 'supervisor', '100002');
        $this->p5Staff(10, 'manager', '100003', overrides: ['branch_id' => 11]);
        $this->p5Staff(11, 'manager', '100004', overrides: ['status' => 'suspended']);
        DB::table('pos_company_settings')->insert(['company_id' => 100, 'key' => 'position_permissions',
            'value' => json_encode(['supervisor' => ['actions' => ['approvals.give' => true]]]), 'created_at' => now(), 'updated_at' => now()]);

        $this->withToken('mdev_ap2')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '100002'])->assertOk()
            ->assertJsonPath('staff.position', 'supervisor');
        $this->withToken('mdev_ap2')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '100003'])->assertStatus(401);
        $this->withToken('mdev_ap2')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '100004'])->assertStatus(401);
        $this->pivot(10, 10);
        $this->withToken('mdev_ap2')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '100003'])->assertOk()
            ->assertJsonPath('staff.id', 10);
    }

    public function test_the_approvers_list_is_the_branch_approvers_with_their_verifier_material(): void
    {
        $this->p5Device('mdev_ap3');
        $this->p5Staff(8, 'manager', '200001', verifier: true);
        $this->p5Staff(9, 'manager', '200002');                                            // no verifier yet
        $this->p5Staff(10, 'cashier', '200003', verifier: true);                           // not an approver
        $this->p5Staff(11, 'manager', '200004', verifier: true, overrides: ['branch_id' => 11]); // another branch
        $this->p5Staff(12, 'manager', '200005', verifier: true, overrides: ['branch_id' => 11]); // also works here
        $this->p5Staff(13, 'manager', '200006', verifier: true, overrides: ['status' => 'terminated', 'deleted_at' => now()]);
        $this->pivot(12, 10);

        $res = $this->withToken('mdev_ap3')->getJson('/api/v1/device/approvers')->assertOk();
        $approvers = $res->json('data.approvers');
        $this->assertSame([8, 9, 12], array_column($approvers, 'staff_id'));
        $this->assertNotNull($res->json('data.as_of'));

        $row = DB::table('pos_staff')->find(8);
        $k = (string) hex2bin($row->pin_offline_key);
        $this->assertSame(['staff_id' => 8, 'uuid' => $row->uuid, 'name' => 'Manager 8', 'position' => 'manager',
            'salt' => $row->pin_offline_salt, 'iterations' => $this->p5Iterations,
            'check' => hash('sha256', 'mithqal-approver-check-v1'.$k)], $approvers[0]);
        $this->assertSame(['salt' => null, 'iterations' => null, 'check' => null],
            array_intersect_key($approvers[1], array_flip(['salt', 'iterations', 'check'])));
        $this->assertStringNotContainsString($row->pin_offline_key, $res->getContent());
        $this->assertStringNotContainsString('pin_hash', $res->getContent());
    }

    public function test_login_makes_the_verifier_works_at_every_pivot_branch_and_returns_branches_and_attendance(): void
    {
        $this->freezeSecond();
        $this->p5Device('mdev_home');
        $this->p5Device('mdev_other', 100, 11);
        $this->p5Device('mdev_third', 100, 12);
        $this->p5Staff(7, 'cashier', '300001');
        $this->pivot(7, 11);

        $res = $this->withToken('mdev_home')->postJson('/api/v1/auth/pos/login', ['pin' => '300001'])->assertOk()
            ->assertJsonPath('data.staff.id', 7)
            ->assertJsonPath('data.staff.branch_id', 10)
            ->assertJsonPath('data.staff.branch_ids', [10, 11])
            ->assertJsonPath('data.branch_ids', [10, 11])
            ->assertJsonPath('data.attendance.open', false)
            ->assertJsonPath('data.staff.attendance.open', false);
        $this->assertStringNotContainsString('pin_offline', $res->getContent());
        $this->assertNotNull(DB::table('pos_staff')->where('id', 7)->value('pin_offline_key'));

        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_other')->postJson('/api/v1/auth/pos/login', ['pin' => '300001'])->assertOk()
            ->assertJsonPath('data.staff.id', 7);
        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_third')->postJson('/api/v1/auth/pos/login', ['pin' => '300001'])->assertStatus(401);

        // An open attendance is reported.
        DB::table('pos_staff_attendance')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'staff_id' => 7, 'clock_in_at' => now()->subHour(), 'source' => 'device', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_home')->postJson('/api/v1/auth/pos/login', ['pin' => '300001'])->assertOk()
            ->assertJsonPath('data.attendance.open', true)
            ->assertJsonPath('data.attendance.clock_in_at', now()->subHour()->utc()->toIso8601String());
    }

    public function test_staff_status_lists_only_the_active_staff_of_this_branch(): void
    {
        $this->p5Device('mdev_ss');
        $this->p5Staff(7, 'cashier', '400001');
        $this->p5Staff(8, 'cashier', '400002', overrides: ['status' => 'suspended']);
        $this->p5Staff(9, 'waiter', '400003', overrides: ['branch_id' => 11]);
        $this->p5Staff(10, 'manager', '400004', overrides: ['branch_id' => 11]);
        $this->p5Staff(11, 'cashier', '400005', overrides: ['company_id' => 200, 'branch_id' => 10]);
        $this->pivot(10, 10);

        $this->withToken('mdev_ss')->getJson('/api/v1/device/staff-status')->assertOk()
            ->assertJsonPath('data.active_staff_ids', [7, 10]);

        PosStaff::query()->whereKey(7)->update(['status' => 'terminated']);
        $this->withToken('mdev_ss')->getJson('/api/v1/device/staff-status')->assertOk()
            ->assertJsonPath('data.active_staff_ids', [10]);
    }

    public function test_an_event_stamped_with_an_inactive_staff_member_settles_but_is_flagged(): void
    {
        $this->p5Device('mdev_flag');
        $this->p5Staff(7, 'cashier', '500001', overrides: ['status' => 'suspended']);

        $this->p5Push('mdev_flag', [$this->p5Event('expense.log', ['category' => 'supplies', 'amount_baisas' => 500, 'staff_id' => 7])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.integrity_flags', ['staff_inactive:7']);
    }
}
