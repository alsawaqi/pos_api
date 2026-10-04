<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A4 — PHASE-1A §2 D-2, D-3, D-5, D-6, D-7 (tests S1–S8 of its §5).
 *
 * Per-device, cache-backed (array store in tests, so every counter is
 * exercised inside one test process).
 */
class PinLockoutTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private const LOGIN = '/api/v1/auth/pos/login';

    private const MANAGER = '/api/v1/device/auth/verify-manager-pin';

    private const UNLOCK = '/api/v1/device/auth/unlock-pin-lock';

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_lock');
        $this->p5Staff(7, 'cashier', '222222');
        $this->p5Staff(8, 'manager', '888888');
    }

    private function attempt(string $url, string $pin, string $token = 'mdev_lock'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson($url, ['pin' => $pin]);
    }

    private function assertLocked(TestResponse $res, int $seconds): void
    {
        $res->assertStatus(423)->assertJsonPath('errors.0.code', 'pin_locked')
            ->assertJsonPath('errors.0.retry_after_seconds', $seconds);
        $this->assertSame((string) $seconds, $res->headers->get('Retry-After'));
    }

    public function test_s1_five_consecutive_wrong_logins_lock_with_a_machine_readable_423(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::LOGIN, '000000')->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');
        }
        $res = $this->attempt(self::LOGIN, '000000');
        $this->assertLocked($res, 60);
        $this->assertIsInt($res->json('errors.0.retry_after_seconds'));
    }

    public function test_s2_a_correct_pin_clears_the_counter_and_the_fifth_after_it_locks(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::LOGIN, '000000')->assertStatus(401);
        }
        $this->attempt(self::LOGIN, '222222')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::LOGIN, '000000')->assertStatus(401);
        }
        $this->assertLocked($this->attempt(self::LOGIN, '000000'), 60);
    }

    public function test_s3_each_attempt_during_a_lock_doubles_the_window_capped_at_15_minutes(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        $this->assertLocked($this->attempt(self::LOGIN, '000000'), 60);
        foreach ([120, 240, 480, 900, 900] as $window) {
            $this->assertLocked($this->attempt(self::LOGIN, '000000'), $window);
        }
    }

    public function test_s4_the_lock_expires_on_its_own(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        $this->travel(61)->seconds();
        $this->attempt(self::LOGIN, '222222')->assertOk()->assertJsonPath('data.staff.id', 7);
    }

    public function test_s5_an_exhausted_login_budget_never_blocks_the_manager_pin_and_the_reverse(): void
    {
        // 10 logins (correct PINs keep the lockout away) exhaust pos-login…
        for ($i = 0; $i < 10; $i++) {
            $this->attempt(self::LOGIN, '222222')->assertOk();
        }
        $this->attempt(self::LOGIN, '222222')->assertStatus(429)->assertJsonPath('errors.0.code', 'too_many_attempts');
        // …while the manager PIN still answers, on its own bucket.
        $this->attempt(self::MANAGER, '888888')->assertOk()->assertJsonPath('ok', true);
        $this->attempt(self::UNLOCK, '888888')->assertOk();

        $this->p5Device('mdev_lock2');
        for ($i = 0; $i < 10; $i++) {
            $this->attempt(self::MANAGER, '888888', 'mdev_lock2')->assertOk();
        }
        $this->attempt(self::MANAGER, '888888', 'mdev_lock2')->assertStatus(429);
        $this->attempt(self::LOGIN, '222222', 'mdev_lock2')->assertOk();
    }

    public function test_s6_a_correct_pin_during_a_lock_is_still_refused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        $this->attempt(self::LOGIN, '222222')->assertStatus(423)->assertJsonPath('errors.0.code', 'pin_locked');
    }

    public function test_s7_a_manager_unlock_clears_the_server_lock_and_a_wrong_one_does_not(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        // A wrong manager PIN does not unlock…
        $this->attempt(self::UNLOCK, '111111')->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');
        $this->attempt(self::LOGIN, '222222')->assertStatus(423);
        // …a cashier's PIN is not a manager PIN…
        $this->attempt(self::UNLOCK, '222222')->assertStatus(401);
        // …a manager's does, and the next login is checked normally.
        $this->attempt(self::UNLOCK, '888888')->assertOk()->assertJsonPath('unlocked', true)->assertJsonPath('staff.name', 'Manager 8');
        $this->attempt(self::LOGIN, '222222')->assertOk();

        // The unlock is itself locked out after 5 wrong PINs (D-5).
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::UNLOCK, '000000')->assertStatus(401);
        }
        $this->assertLocked($this->attempt(self::UNLOCK, '000000'), 60);
        $this->attempt(self::UNLOCK, '888888')->assertStatus(423);
    }

    public function test_s8_the_manager_pin_lockout_is_independent_of_the_login_lockout(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt(self::MANAGER, '000000')->assertStatus(401);
        }
        $this->assertLocked($this->attempt(self::MANAGER, '000000'), 60);
        // The manager gate is locked; the login is not.
        $this->attempt(self::MANAGER, '888888')->assertStatus(423);
        $this->attempt(self::LOGIN, '222222')->assertOk();

        // And the reverse: a locked login leaves the manager gate open.
        $this->travel(16)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        $this->attempt(self::LOGIN, '222222')->assertStatus(423);
        $this->attempt(self::MANAGER, '888888')->assertOk();
    }

    public function test_two_devices_lock_independently(): void
    {
        $this->p5Device('mdev_lock_b');
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::LOGIN, '000000');
        }
        $this->attempt(self::LOGIN, '222222')->assertStatus(423);
        $this->attempt(self::LOGIN, '222222', 'mdev_lock_b')->assertOk();
    }

    public function test_the_kitchen_walk_up_pin_shares_the_manager_lockout(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('/api/v1/device/auth/verify-kitchen-pin', '000000');
        }
        $this->attempt(self::MANAGER, '888888')->assertStatus(423)->assertJsonPath('errors.0.code', 'pin_locked');
    }

    public function test_every_manager_pin_route_is_on_the_manager_pin_bucket(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['device.verify-manager-pin', 'device.verify-kitchen-pin', 'device.unlock-pin-lock', 'device.tables.combine',
            'device.productions.cancel', 'device.disposition.store', 'device.qr.pending-orders.payment-review'] as $name) {
            $middleware = $routes->getByName($name)?->gatherMiddleware() ?? [];
            $this->assertContains('throttle:manager-pin', $middleware, $name);
            $this->assertNotContains('throttle:pos-login', $middleware, $name);
        }
        $this->assertContains('throttle:pos-login', $routes->getByName('pos.login')->gatherMiddleware());
    }
}
