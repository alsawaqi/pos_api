<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Tests\TestCase;

final class IpRateLimitKeyTest extends TestCase
{
    public function test_every_native_ipv6_ip_bucket_uses_the_client_64(): void
    {
        $first = $this->resolveIpLimits('2001:db8:abcd:1234:1111:2222:3333:4444');
        $sameNetwork = $this->resolveIpLimits('2001:db8:abcd:1234:aaaa:bbbb:cccc:dddd');
        $differentNetwork = $this->resolveIpLimits('2001:db8:abcd:1235::1');

        $this->assertSame($this->expectedIpLimits('2001:db8:abcd:1234::/64'), $first);
        $this->assertSame($first, $sameNetwork);
        $this->assertNotSame($first, $differentNetwork);
    }

    public function test_ipv4_mapped_ipv6_uses_the_canonical_ipv4_bucket(): void
    {
        $ipv4 = $this->resolveIpLimits('192.0.2.44');
        $mappedIpv6 = $this->resolveIpLimits('::ffff:192.0.2.44');

        $this->assertSame($this->expectedIpLimits('192.0.2.44'), $ipv4);
        $this->assertSame($ipv4, $mappedIpv6);
    }

    public function test_malformed_ips_share_one_fixed_fallback_bucket(): void
    {
        $first = $this->resolveIpLimits('not-an-ip');
        $second = $this->resolveIpLimits('also-not-an-ip');

        $this->assertSame($this->expectedIpLimits('unknown'), $first);
        $this->assertSame($first, $second);
    }

    public function test_authenticated_device_keys_are_not_changed(): void
    {
        $request = $this->requestFor('2001:db8:abcd:1234::1');
        $request->setUserResolver(static fn () => new GenericUser(['id' => 'device-42']));

        $limits = $this->resolveIpLimitsForRequest($request);

        $this->assertSame(['device:device-42', 120, 60], $limits['device-api']);
        $this->assertSame(['pos-login:device-42', 10, 60], $limits['pos-login']);
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    private function resolveIpLimits(string $ip): array
    {
        return $this->resolveIpLimitsForRequest($this->requestFor($ip));
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    private function resolveIpLimitsForRequest(Request $request): array
    {
        $rateLimiter = app(RateLimiter::class);

        return [
            'device-pair' => $this->describe($this->limits($rateLimiter, 'device-pair', $request)[0]),
            'device-api' => $this->describe($this->limits($rateLimiter, 'device-api', $request)[0]),
            'qr-bind' => $this->describe($this->limits($rateLimiter, 'qr-bind', $request)[0]),
            'qr-read' => $this->describe($this->limits($rateLimiter, 'qr-read', $request)[1]),
            'qr-quote' => $this->describe($this->limits($rateLimiter, 'qr-quote', $request)[1]),
            'qr-checkout' => $this->describe($this->limits($rateLimiter, 'qr-checkout', $request)[0]),
            'pos-login' => $this->describe($this->limits($rateLimiter, 'pos-login', $request)[0]),
        ];
    }

    /**
     * @return list<Limit>
     */
    private function limits(RateLimiter $rateLimiter, string $name, Request $request): array
    {
        $definition = $rateLimiter->limiter($name);
        $this->assertNotNull($definition);

        $resolved = $definition($request);

        return is_array($resolved) ? $resolved : [$resolved];
    }

    /**
     * @return array{string, int, int}
     */
    private function describe(Limit $limit): array
    {
        return [(string) $limit->key, $limit->maxAttempts, $limit->decaySeconds];
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    private function expectedIpLimits(string $ipKey): array
    {
        return [
            'device-pair' => ['ip:'.$ipKey, 10, 60],
            'device-api' => ['device:'.$ipKey, 120, 60],
            'qr-bind' => ['qr-bind:ip:'.$ipKey, 10, 60],
            'qr-read' => ['qr-read:ip:'.$ipKey, 3000, 60],
            'qr-quote' => ['qr-quote:ip:'.$ipKey, 600, 60],
            'qr-checkout' => ['qr-checkout:ip:'.$ipKey, 10, 60],
            'pos-login' => ['pos-login:'.$ipKey, 10, 60],
        ];
    }

    private function requestFor(string $ip): Request
    {
        $request = Request::create(
            '/api/v1/public/qr/checkout',
            'POST',
            [
                'kiosk_id' => 'kiosk-1',
                'token' => str_repeat('t', 64),
            ],
            server: ['REMOTE_ADDR' => $ip],
        );
        $request->headers->set('X-QR-Session', 'session-credential');

        return $request;
    }
}
