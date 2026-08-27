<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_ops/trusted-proxy-context', static fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
        ]));

        Route::post('/_ops/qr-checkout-budget', static fn (Request $request) => response()->json([
            'ip' => $request->ip(),
        ]))->middleware('throttle:qr-checkout');
    }

    public function test_nginx_established_remote_address_and_https_are_used(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.42',
        ])->getJson('https://localhost/_ops/trusted-proxy-context')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.42',
                'scheme' => 'https',
                'host' => 'localhost',
                'port' => 443,
            ]);
    }

    public function test_forwarded_headers_cannot_override_nginx_established_request_context(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.55',
            'SERVER_PORT' => 8088,
            'HTTPS' => 'off',
        ])->withHeaders([
            'Host' => 'direct.example.test:8088',
            'X-Forwarded-For' => '1.2.3.4',
            'X-Forwarded-Host' => 'forged.example.test',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])->getJson('/_ops/trusted-proxy-context')
            ->assertOk()
            ->assertExactJson([
                'ip' => '192.0.2.55',
                'scheme' => 'http',
                'host' => 'localhost',
                'port' => 8088,
            ]);
    }

    public function test_laravel_has_no_trusted_proxy_addresses(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.60',
        ])->withHeader('X-Forwarded-For', '1.2.3.4')
            ->getJson('/_ops/trusted-proxy-context')
            ->assertOk()
            ->assertJsonPath('ip', '192.0.2.60');

        $this->assertSame([], Request::getTrustedProxies());
    }

    public function test_checkout_budget_uses_nginx_established_client_not_forwarded_headers(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->checkoutBudgetRequest(
                '198.51.100.71',
                'customer-a-session',
                '203.0.113.'.(string) $attempt,
            )
                ->assertOk()
                ->assertJsonPath('ip', '198.51.100.71');
        }

        $this->checkoutBudgetRequest('198.51.100.71', 'customer-a-session', '203.0.113.11')
            ->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'rate_limited');

        $this->checkoutBudgetRequest('198.51.100.72', 'customer-b-session', '203.0.113.11')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.72');
    }

    private function checkoutBudgetRequest(
        string $clientIp,
        string $session,
        string $forgedForwardedFor,
    ): TestResponse {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $clientIp,
        ])->withHeaders([
            'X-Forwarded-For' => $forgedForwardedFor,
            'X-QR-Session' => $session,
        ])->postJson('/_ops/qr-checkout-budget');
    }
}
