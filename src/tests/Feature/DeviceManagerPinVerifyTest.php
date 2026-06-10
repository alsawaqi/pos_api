<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P-F1 — manager PIN fallback (POST /api/v1/device/auth/verify-manager-pin).
 *
 * A paired device (company 100 / branch 10) verifies that a submitted PIN
 * belongs to an ACTIVE staff member of ITS company whose position is in the
 * company's `manager_approval_positions` policy (default managers-only).
 * Any branch of the company qualifies (roaming area manager); other
 * companies never do. Success returns { ok, staff } so the device can stamp
 * the approver onto the gated action.
 */
class DeviceManagerPinVerifyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/device/auth/verify-manager-pin';

    private function device(string $token = 'mdev_mgr', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function staff(string $pin = '123456', array $overrides = []): int
    {
        return (int) DB::table('pos_staff')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => 'Mona',
            'pin_hash' => Hash::make($pin),
            'position' => 'manager',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  list<string>  $positions
     */
    private function policy(array $positions, int $company = 100): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => $company,
            'key' => 'manager_approval_positions',
            'value' => json_encode($positions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_valid_manager_pin_returns_ok_and_the_approver(): void
    {
        $this->device();
        $id = $this->staff('123456');

        $res = $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertOk();

        $res->assertJsonPath('ok', true)
            ->assertJsonPath('staff.id', $id)
            ->assertJsonPath('staff.name', 'Mona')
            ->assertJsonPath('staff.position', 'manager');
        // pin_hash must never leak.
        $this->assertNull($res->json('staff.pin_hash'));
    }

    public function test_a_cashiers_pin_is_rejected_under_the_default_policy(): void
    {
        $this->device();
        $this->staff('123456', ['position' => 'cashier']);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_pin');
    }

    public function test_a_wrong_pin_is_rejected(): void
    {
        $this->device();
        $this->staff('123456');

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_pin');
    }

    public function test_a_suspended_manager_cannot_authorize(): void
    {
        $this->device();
        $this->staff('123456', ['status' => 'suspended']);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_a_custom_approval_policy_is_honored(): void
    {
        $this->device();
        $this->policy(['supervisor']);
        $this->staff('111222', ['position' => 'supervisor', 'name' => 'Sami']);
        $this->staff('123456', ['position' => 'manager']);

        // The supervisor now authorizes…
        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '111222'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('staff.position', 'supervisor');

        // …and the manager — no longer in the policy — does NOT.
        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_a_manager_from_another_branch_of_the_company_is_accepted(): void
    {
        // Branch scoping choice: a roaming area manager (same company,
        // different branch) may approve at this device.
        $this->device(); // branch 10
        $this->staff('123456', ['branch_id' => 11]);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_a_manager_from_another_company_never_matches(): void
    {
        // Two companies: the device belongs to 100; an identically-PINned
        // manager at 200 must never authorize here (tenant isolation).
        $this->device(); // company 100
        $this->staff('123456', ['company_id' => 200, 'branch_id' => 20]);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_pin');
    }

    public function test_a_blocked_device_token_is_rejected(): void
    {
        Device::factory()->paired('mdev_blocked_mgr')->create([
            'company_id' => 100, 'branch_id' => 10, 'status' => 'blocked',
        ]);
        $this->staff('123456');

        // The pos_device guard drops blocked/inactive devices -> 401, same as
        // every other device endpoint.
        $this->withToken('mdev_blocked_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_verification_requires_a_device_token(): void
    {
        $this->staff('123456');

        $this->postJson(self::URL, ['pin' => '123456'])->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned_mgr')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_unassigned_mgr')
            ->postJson(self::URL, ['pin' => '123456'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'device_unassigned');
    }

    public function test_the_pin_must_be_4_to_8_digits(): void
    {
        $this->device();

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);

        $this->withToken('mdev_mgr')
            ->postJson(self::URL, ['pin' => '123456789'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_verification_is_rate_limited_per_device(): void
    {
        $this->device();
        $this->staff('123456');

        // Shares the pos-login limiter: 10 attempts/min; the 11th is throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->withToken('mdev_mgr')->postJson(self::URL, ['pin' => '000000'])
                ->assertStatus(401);
        }

        $this->withToken('mdev_mgr')->postJson(self::URL, ['pin' => '000000'])
            ->assertStatus(429);
    }
}
