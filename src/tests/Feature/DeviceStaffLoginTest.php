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
 * Phase 8.6 — POS staff PIN login (POST /api/v1/auth/pos/login).
 *
 * A paired device (company 100 / branch 10) authenticates the operator by
 * PIN. PINs are bcrypt-hashed and unique per company; login is scoped to the
 * device's company + branch and to active staff.
 */
class DeviceStaffLoginTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_pos', int $company = 100, int $branch = 10): Device
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
            'name' => 'Ali',
            'pin_hash' => Hash::make($pin),
            'position' => 'cashier',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_valid_pin_returns_the_staff_profile(): void
    {
        $this->device();
        $id = $this->staff('123456');

        $res = $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertOk();

        $res->assertJsonPath('data.staff.id', $id)
            ->assertJsonPath('data.staff.name', 'Ali')
            ->assertJsonPath('data.staff.position', 'cashier')
            ->assertJsonPath('data.staff.branch_id', 10)
            ->assertJsonPath('errors', []);
        // pin_hash must never leak.
        $this->assertNull($res->json('data.staff.pin_hash'));
        // last_login_at stamped.
        $this->assertNotNull(DB::table('pos_staff')->where('id', $id)->value('last_login_at'));
    }

    public function test_a_wrong_pin_is_rejected(): void
    {
        $this->device();
        $this->staff('123456');

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_pin');
    }

    public function test_a_suspended_staff_member_cannot_log_in(): void
    {
        $this->device();
        $this->staff('123456', ['status' => 'suspended']);

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_staff_from_another_branch_cannot_log_in(): void
    {
        $this->device(); // branch 10
        $this->staff('123456', ['branch_id' => 11]);

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_staff_from_another_company_cannot_log_in(): void
    {
        $this->device(); // company 100
        $this->staff('123456', ['company_id' => 200]);

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_login_requires_a_device_token(): void
    {
        $this->staff('123456');

        $this->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_unassigned')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(409);
    }

    public function test_the_pin_must_be_4_to_6_digits(): void
    {
        $this->device();

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);

        $this->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_login_is_rate_limited_per_device(): void
    {
        $this->device();
        $this->staff('123456');

        // 10 attempts/min allowed; the 11th is throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->withToken('mdev_pos')->postJson('/api/v1/auth/pos/login', ['pin' => '000000'])
                ->assertStatus(401);
        }

        $this->withToken('mdev_pos')->postJson('/api/v1/auth/pos/login', ['pin' => '000000'])
            ->assertStatus(429);
    }
}
