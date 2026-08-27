<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;
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

    public function test_trusted_subnet_accepts_forwarded_client_and_origin_headers(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.24.15.9',
            'SERVER_PORT' => 8088,
            'HTTPS' => 'off',
        ])->withHeaders([
            'Host' => 'direct.internal:8088',
            'X-Forwarded-For' => '198.51.100.42',
            'X-Forwarded-Host' => 'api.example.test',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])->getJson('/_ops/trusted-proxy-context')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.42',
                'scheme' => 'https',
                'host' => 'api.example.test',
                'port' => 443,
            ]);
    }

    public function test_outside_peer_cannot_forge_forwarded_client_or_origin_headers(): void
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

    public function test_framework_default_header_set_contains_every_required_forwarded_header(): void
    {
        $middleware = app(TrustProxies::class);
        $headers = new ReflectionProperty($middleware, 'headers');

        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
            $headers->getValue($middleware),
        );
    }

    public function test_checkout_budgets_are_independent_per_forwarded_client_behind_one_proxy(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->checkoutBudgetRequest('198.51.100.71', 'customer-a-session')
                ->assertOk()
                ->assertJsonPath('ip', '198.51.100.71');
        }

        $this->checkoutBudgetRequest('198.51.100.71', 'customer-a-session')
            ->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'rate_limited');

        $this->checkoutBudgetRequest('198.51.100.72', 'customer-b-session')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.72');
    }

    private function checkoutBudgetRequest(string $clientIp, string $session): TestResponse
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => '172.24.10.10',
        ])->withHeaders([
            'X-Forwarded-For' => $clientIp,
            'X-QR-Session' => $session,
        ])->postJson('/_ops/qr-checkout-budget');
    }
}
