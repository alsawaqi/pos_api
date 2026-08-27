<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 8 hardening — rate limiting on the pairing endpoint and JSON
 * error responses regardless of the client's Accept header.
 */
class DeviceApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pairing_is_rate_limited_per_nginx_established_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.81']);

        // The per-IP limiter allows 10 attempts/minute. The first 10 fail
        // validation (unknown token), the 11th is blocked with 429 before it
        // can reach the action — an attacker can't grind activation tokens.
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withHeader('X-Forwarded-For', '203.0.113.'.($i + 1))
                ->postJson('/api/v1/auth/device/pair', [
                    'kiosk_id' => 'KIOSK-BRUTE',
                    'activation_token' => "guess-{$i}",
                ])->assertStatus(422);
            $this->assertSame('198.51.100.81', $response->baseRequest?->ip());
        }

        $this->withHeader('X-Forwarded-For', '203.0.113.11')
            ->postJson('/api/v1/auth/device/pair', [
                'kiosk_id' => 'KIOSK-BRUTE',
                'activation_token' => 'guess-final',
            ])->assertStatus(429);
    }

    public function test_device_api_limiter_remains_per_authenticated_device_with_nginx_client_ip(): void
    {
        Route::middleware(['auth:pos_device', 'throttle:device-api'])
            ->get('/_ops/device-api-budget', static fn (Request $request) => response()->json([
                'device_id' => $request->user()?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ]));

        $firstDevice = Device::factory()->paired('mdev_budget_a')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'status' => 'active',
        ]);
        $secondDevice = Device::factory()->paired('mdev_budget_b')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'status' => 'active',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.82'])
            ->withHeader('X-Forwarded-For', '203.0.113.82');

        for ($attempt = 1; $attempt <= 120; $attempt++) {
            $this->withToken('mdev_budget_a')
                ->getJson('/_ops/device-api-budget')
                ->assertOk()
                ->assertJsonPath('device_id', $firstDevice->id)
                ->assertJsonPath('ip', '198.51.100.82');
        }

        $this->withToken('mdev_budget_a')
            ->getJson('/_ops/device-api-budget')
            ->assertStatus(429);

        $this->app['auth']->forgetGuards();

        $this->withToken('mdev_budget_b')
            ->getJson('/_ops/device-api-budget')
            ->assertOk()
            ->assertJsonPath('device_id', $secondDevice->id)
            ->assertJsonPath('ip', '198.51.100.82');
    }

    public function test_a_legitimate_pair_succeeds_within_the_limit(): void
    {
        $device = Device::factory()->create(['kiosk_id' => 'KIOSK-OK']);
        DeviceActivationToken::factory()->for($device)->forPlaintext('good_tok')->create();

        $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'KIOSK-OK',
            'activation_token' => 'good_tok',
        ])->assertOk();
    }

    public function test_unauthenticated_request_returns_json_even_without_an_accept_header(): void
    {
        // A raw POST with no `Accept: application/json` must still get a JSON
        // 401 — never an HTML page or a redirect to a non-existent login route.
        $res = $this->call('POST', '/api/v1/device/heartbeat');

        $res->assertStatus(401);
        $this->assertStringContainsString(
            'application/json',
            (string) $res->headers->get('Content-Type'),
        );
    }

    public function test_a_404_on_an_api_path_renders_json(): void
    {
        $res = $this->call('GET', '/api/v1/does-not-exist');

        $res->assertStatus(404);
        $this->assertStringContainsString(
            'application/json',
            (string) $res->headers->get('Content-Type'),
        );
    }

    public function test_an_active_device_token_is_accepted(): void
    {
        Device::factory()->paired('mdev_live')->create(['company_id' => 100, 'branch_id' => 10, 'status' => 'active']);

        $this->withToken('mdev_live')->getJson('/api/v1/device/orders/active')->assertOk();
    }

    public function test_a_blocked_device_token_is_rejected(): void
    {
        // A decommissioned/suspended device keeps its token column but must not
        // authenticate (blueprint device-lifecycle revocation).
        Device::factory()->paired('mdev_blocked')->create(['company_id' => 100, 'branch_id' => 10, 'status' => 'blocked']);

        $this->withToken('mdev_blocked')->getJson('/api/v1/device/orders/active')->assertStatus(401);
    }

    public function test_an_inactive_device_token_is_rejected(): void
    {
        Device::factory()->paired('mdev_off')->create(['company_id' => 100, 'branch_id' => 10, 'status' => 'inactive']);

        $this->withToken('mdev_off')->getJson('/api/v1/device/orders/active')->assertStatus(401);
    }
}
