<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A8 — the training-mode safety net: any sync event or device call
 * carrying training: true is refused (a training event is never stored).
 */
class TrainingRefusalTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_att');
        $this->p5Staff(7, 'cashier', '700007');
    }

    public function test_a_training_event_is_refused_and_never_stored(): void
    {
        $this->p5Product();
        $uuid = (string) Str::uuid();
        $event = $this->p5Create($uuid, [], ['training' => true, 'auth_v' => 1]);
        $this->p5Push('mdev_att', [$event, $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]], ['training' => true])])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.code', 'training_refused')
            ->assertJsonPath('data.results.0.result.permanent', true)
            ->assertJsonPath('data.results.1.result.code', 'training_refused');
        $this->assertSame(0, DB::table('pos_sync_events')->count());
        $this->assertSame(0, DB::table('pos_orders')->count());

        // A training flag inside the order is caught too.
        $nested = $this->p5Create((string) Str::uuid(), ['training' => true]);
        $this->p5Push('mdev_att', [$nested])->assertOk()->assertJsonPath('data.results.0.result.code', 'training_refused');
    }

    public function test_a_training_endpoint_call_is_refused(): void
    {
        $this->withToken('mdev_att')->postJson('/api/v1/auth/pos/login', ['pin' => '700007', 'training' => true])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'training_refused');
        $this->withToken('mdev_att')->postJson('/api/v1/device/products/1/sold-out?training=1', ['sold_out' => true, 'staff_id' => 7])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'training_refused');
        $this->withToken('mdev_att')->getJson('/api/v1/device/staff-status')->assertOk();
    }
}
