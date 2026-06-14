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
 * P-G1.6 — POST /device/auth/verify-kitchen-pin: the Kitchen walk-up
 * gate. Verifies a PIN against ACTIVE staff whose position is allowed —
 * the 'kitchen' role always is, plus whatever positions the merchant
 * ticked (no managers-only default) — so a chef can open the Kitchen
 * screen on a cashier's till session.
 *
 * Staff: chef Sami (kitchen, PIN 1111), manager Mona (manager, PIN
 * 4321), cashier Omar (cashier, PIN 2222), all company 100 / branch 10.
 */
class DeviceKitchenPinVerifyTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_kpin', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedStaff(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_staff')->insert([
            ['id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Sami', 'pin_hash' => Hash::make('1111'), 'position' => 'kitchen', 'status' => 'active'] + $t,
            ['id' => 8, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Mona', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active'] + $t,
            ['id' => 9, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Omar', 'pin_hash' => Hash::make('2222'), 'position' => 'cashier', 'status' => 'active'] + $t,
        ]);
    }

    private function setKitchenPolicy(array $positions): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'kitchen_positions',
            'value' => json_encode($positions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_kitchen_role_always_passes_with_no_managers_only_default(): void
    {
        $this->seedStaff();
        $this->device();

        // No policy row: the kitchen role ALWAYS has access, so the chef passes.
        $res = $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '1111'])
            ->assertOk();
        $this->assertTrue($res->json('ok'));
        $this->assertSame('Sami', $res->json('staff.name'));

        // No managers-only fallback: a manager does NOT pass until the merchant
        // ticks 'manager' explicitly.
        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '4321'])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_pin');
    }

    public function test_a_ticked_role_passes_and_the_kitchen_role_still_passes(): void
    {
        $this->seedStaff();
        $this->device();
        $this->setKitchenPolicy(['manager']); // give managers kitchen access too

        // The ticked manager now passes...
        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '4321'])
            ->assertOk()
            ->assertJsonPath('staff.name', 'Mona');

        // ...and the kitchen role still passes (always implicit)...
        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '1111'])
            ->assertOk()
            ->assertJsonPath('staff.name', 'Sami');

        // ...while a cashier (neither ticked nor the kitchen role) still fails.
        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '2222'])
            ->assertStatus(401);
    }

    public function test_the_merchant_policy_decides_who_passes(): void
    {
        $this->seedStaff();
        $this->device();
        $this->setKitchenPolicy(['kitchen']);

        // The chef passes and the device learns WHO is cooking.
        $res = $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '1111'])
            ->assertOk();
        $this->assertSame(7, $res->json('staff.id'));
        $this->assertSame('kitchen', $res->json('staff.position'));

        // Cashier and (now-excluded) manager fail IDENTICALLY — the
        // response never reveals whether a PIN exists.
        foreach (['2222', '4321', '9999'] as $pin) {
            $this->withToken('mdev_kpin')
                ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => $pin])
                ->assertStatus(401)
                ->assertJsonPath('errors.0.code', 'invalid_pin');
        }
    }

    public function test_cross_company_staff_never_pass(): void
    {
        $this->seedStaff();
        $this->device();
        $this->setKitchenPolicy(['kitchen']);

        // A foreign company's kitchen staff with the same PIN.
        Device::factory()->paired('mdev_foreign')->create(['company_id' => 200, 'branch_id' => 20]);
        DB::table('pos_staff')->insert([
            'id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'branch_id' => 20,
            'name' => 'Other Chef', 'pin_hash' => Hash::make('5555'), 'position' => 'kitchen', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '5555'])
            ->assertStatus(401);
    }

    public function test_validates_the_pin_shape_and_assignment(): void
    {
        $this->seedStaff();
        $this->device();

        $this->withToken('mdev_kpin')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => 'abcd'])
            ->assertStatus(422);

        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);
        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_unassigned')
            ->postJson('/api/v1/device/auth/verify-kitchen-pin', ['pin' => '4321'])
            ->assertStatus(409);
    }
}
