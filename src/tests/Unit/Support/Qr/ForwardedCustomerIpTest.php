<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Qr;

use App\Support\Qr\ForwardedCustomerIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Sentry\Laravel\Facade as Sentry;
use Sentry\Severity;
use Sentry\State\Scope;
use Tests\TestCase;

final class ForwardedCustomerIpTest extends TestCase
{
    private const SECRET = 'w2-resolver-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'qr.bff_client_ip_secret' => self::SECRET,
        ]);
        Cache::flush();
    }

    public function test_valid_forwarded_addresses_are_returned_without_rewriting(): void
    {
        $resolver = app(ForwardedCustomerIp::class);

        $this->assertSame('203.0.113.45', $resolver->resolve(
            $this->request('198.51.100.20', self::SECRET, '203.0.113.45'),
        ));
        $this->assertSame('2001:db8::1234', $resolver->resolve(
            $this->request('198.51.100.20', self::SECRET, '2001:db8::1234'),
        ));
        $this->assertSame('::ffff:203.0.113.45', $resolver->resolve(
            $this->request('198.51.100.20', self::SECRET, '::ffff:203.0.113.45'),
        ));
        $this->assertSame('0.0.0.0', $resolver->resolve(
            $this->request('198.51.100.20', self::SECRET, '0.0.0.0'),
        ));
    }

    public function test_every_gate_failure_returns_the_socket_ip(): void
    {
        $resolver = app(ForwardedCustomerIp::class);
        $socketIp = '198.51.100.21';

        config(['qr.bff_client_ip_secret' => '']);
        $this->assertSame($socketIp, $resolver->resolve(
            $this->request($socketIp, self::SECRET, '203.0.113.51'),
        ));

        config(['qr.bff_client_ip_secret' => self::SECRET]);
        $this->assertSame($socketIp, $resolver->resolve(
            $this->request($socketIp, null, '203.0.113.52'),
        ));
        $this->assertSame($socketIp, $resolver->resolve(
            $this->request($socketIp, 'wrong-secret', '203.0.113.53'),
        ));
        $this->assertSame($socketIp, $resolver->resolve(
            $this->request($socketIp, self::SECRET, null),
        ));
        $this->assertSame($socketIp, $resolver->resolve(
            $this->request($socketIp, self::SECRET, 'not-an-ip'),
        ));
    }

    public function test_missing_socket_ip_has_the_existing_empty_string_fallback(): void
    {
        config(['qr.bff_client_ip_secret' => '']);
        $request = Request::create('/_ops/w2-resolver', 'GET');
        $request->server->remove('REMOTE_ADDR');

        $this->assertSame('', app(ForwardedCustomerIp::class)->resolve($request));
    }

    public function test_mismatch_warning_is_rate_limited_and_contains_no_header_values(): void
    {
        $scope = Mockery::mock(Scope::class);
        $scope->shouldReceive('setContext')
            ->once()
            ->with('qr_bff_client_ip', ['reason' => 'auth_mismatch'])
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('QR BFF customer IP headers ignored', ['reason' => 'auth_mismatch']);
        Sentry::shouldReceive('captureMessage')
            ->once()
            ->with(
                'QR BFF customer IP headers ignored',
                Mockery::on(static fn (mixed $severity): bool => $severity instanceof Severity
                    && $severity->isEqualTo(Severity::warning())),
            );
        Sentry::shouldReceive('withScope')
            ->once()
            ->andReturnUsing(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $request = $this->request('198.51.100.22', 'never-log-this-secret', '203.0.113.54');
        $resolver = app(ForwardedCustomerIp::class);

        $this->assertSame('198.51.100.22', $resolver->resolve($request));
        $this->assertSame('198.51.100.22', $resolver->resolve($request));
    }

    public function test_warning_transport_failure_never_changes_resolution(): void
    {
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache unavailable'));

        $resolved = app(ForwardedCustomerIp::class)->resolve(
            $this->request('198.51.100.23', 'wrong-secret', '203.0.113.55'),
        );

        $this->assertSame('198.51.100.23', $resolved);
    }

    private function request(string $socketIp, ?string $secret, ?string $forwardedIp): Request
    {
        $request = Request::create(
            '/api/v1/public/qr/checkout',
            'POST',
            server: ['REMOTE_ADDR' => $socketIp],
        );
        if ($secret !== null) {
            $request->headers->set(ForwardedCustomerIp::AUTH_HEADER, $secret);
        }
        if ($forwardedIp !== null) {
            $request->headers->set(ForwardedCustomerIp::IP_HEADER, $forwardedIp);
        }

        return $request;
    }
}
