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

    /**
     * Inserts a real branch row (fenced by default) and returns its id.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function branch(array $overrides = []): int
    {
        return (int) DB::table('pos_branches')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Test Branch',
            'latitude' => 23.5880,
            'longitude' => 58.3829,
            'geofence_radius_m' => 500,
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
            ->assertStatus(401)->assertJsonPath('code', 'device_reactivation_required')->assertJsonMissingPath('errors');
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
        $this->device('mdev_pos_other');
        $this->staff('123456');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90']);

        // The route is authenticated before throttling, so the existing key
        // remains the device even when forged forwarding headers vary.
        // PHASE-1A D-3 (LAUNCH-P5): wrong PINs now LOCK at the 5th (423), so
        // the 10/min limiter is shown with correct PINs, which clear the lock
        // counter each time; the 11th call is throttled with the D-6 payload.
        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.(100 + $i))
                ->withToken('mdev_pos')
                ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
                ->assertOk();
        }

        $throttled = $this->withHeader('X-Forwarded-For', '198.51.100.110')
            ->withToken('mdev_pos')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'too_many_attempts');
        $this->assertIsInt($throttled->json('errors.0.retry_after_seconds'));

        $this->app['auth']->forgetGuards();

        // A second authenticated device behind the same client/proxy path has
        // an independent budget.
        $this->withToken('mdev_pos_other')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '000000'])
            ->assertStatus(401);
    }

    public function test_login_at_a_fenced_branch_enforces_location(): void
    {
        $branchId = $this->branch();
        Device::factory()->paired('mdev_fenced')->create([
            'company_id' => 100,
            'branch_id' => $branchId,
        ]);
        $this->staff('123456', ['company_id' => 100, 'branch_id' => $branchId]);

        // Inside the fence -> login succeeds.
        $this->withToken('mdev_fenced')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456', 'lat' => 23.5881, 'lng' => 58.3830])
            ->assertOk()
            ->assertJsonPath('data.staff.name', 'Ali');

        // Outside the fence -> rejected.
        $this->withToken('mdev_fenced')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456', 'lat' => 23.7000, 'lng' => 58.5000])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'outside_geofence');

        // Fenced branch but no GPS supplied -> fail-closed.
        $this->withToken('mdev_fenced')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'location_required');
    }

    public function test_login_at_an_unfenced_branch_needs_no_location(): void
    {
        // A real branch row with NO coordinates = no fence to enforce.
        $branchId = $this->branch(['latitude' => null, 'longitude' => null]);
        Device::factory()->paired('mdev_open')->create([
            'company_id' => 100,
            'branch_id' => $branchId,
        ]);
        $this->staff('123456', ['company_id' => 100, 'branch_id' => $branchId]);

        $this->withToken('mdev_open')
            ->postJson('/api/v1/auth/pos/login', ['pin' => '123456'])
            ->assertOk();
    }
}
