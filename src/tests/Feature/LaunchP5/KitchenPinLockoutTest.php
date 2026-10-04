<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (L1) — the kitchen walk-up PIN shares the
 * manager-PIN lockout, but a correct kitchen PIN clears that counter only
 * when its person holds approvals.give. A kitchen worker's own PIN between
 * wrong manager PINs no longer keeps the lock from ever firing.
 */
class KitchenPinLockoutTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Device('mdev_kpin');
        $this->p5Staff(8, 'manager', '800008');
        $this->p5Staff(11, 'kitchen', '111111');
    }

    private function pin(string $route, string $pin): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken('mdev_kpin')->postJson('/api/v1/device/auth/'.$route, ['pin' => $pin]);
    }

    public function test_a_kitchen_workers_own_pin_does_not_reset_the_manager_pin_counter(): void
    {
        // Review probe 6: four wrong manager PINs, then the cook's own PIN, repeated.
        $statuses = [];
        for ($round = 0; $round < 5; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $statuses[] = $this->pin('verify-manager-pin', sprintf('%06d', 300000 + $round * 10 + $i))->status();
            }
            $kitchen = $this->pin('verify-kitchen-pin', '111111')->status();
            if ($kitchen === 423) {
                $statuses[] = 423;
                break;
            }
        }

        $this->assertSame([401, 401, 401, 401], array_slice($statuses, 0, 4));
        $this->assertContains(423, $statuses);
    }

    public function test_a_managers_correct_kitchen_pin_still_clears_it(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->pin('verify-manager-pin', sprintf('%06d', 300000 + $i))->assertStatus(401);
        }
        // Managers hold kitchen.screen and approvals.give by default.
        $this->pin('verify-kitchen-pin', '800008')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->pin('verify-manager-pin', sprintf('%06d', 310000 + $i))->assertStatus(401);
        }
        $this->pin('verify-manager-pin', '320000')->assertStatus(423);
    }
}
