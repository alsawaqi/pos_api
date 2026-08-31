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
    private const BFF_SECRET = 'w2-unit-test-bff-secret';

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

    public function test_authenticated_bff_ip_is_used_by_exactly_the_eight_public_qr_axes(): void
    {
        config(['qr.bff_client_ip_secret' => self::BFF_SECRET]);
        $request = $this->requestFor('198.51.100.40');
        $this->withForwardedIp($request, '2001:db8:abcd:1234:1111:2222:3333:4444');

        $limits = $this->resolveIpLimitsForRequest($request);
        $network = '2001:db8:abcd:1234::/64';

        $this->assertSame(['qr-bind:ip:'.$network, 10, 60], $limits['qr-bind']);
        $this->assertSame(['qr-read:ip:'.$network, 3000, 60], $limits['qr-read']);
        $this->assertSame(['qr-quote:ip:'.$network, 600, 60], $limits['qr-quote']);
        $this->assertSame(['qr-checkout:ip:'.$network, 10, 60], $limits['qr-checkout']);
        $this->assertSame(['qr-table-read:ip:'.$network, 3000, 60], $limits['qr-table-read']);
        $this->assertSame(['qr-table-bind:ip:'.$network, 600, 60], $limits['qr-table-bind']);
        $this->assertSame(['qr-dine-in-round:ip:'.$network, 400, 60], $limits['qr-dine-in-round']);
        $this->assertSame(['qr-dine-in-finish:ip:'.$network, 200, 60], $limits['qr-dine-in-finish']);

        $this->assertSame(['ip:198.51.100.40', 10, 60], $limits['device-pair']);
        $this->assertSame(['device:198.51.100.40', 120, 60], $limits['device-api']);
        $this->assertSame(['pos-login:198.51.100.40', 10, 60], $limits['pos-login']);
    }

    public function test_wrong_bff_secret_is_byte_identical_to_socket_ip_behavior(): void
    {
        config(['qr.bff_client_ip_secret' => self::BFF_SECRET]);
        $baseline = $this->resolveIpLimits('198.51.100.41');
        $request = $this->requestFor('198.51.100.41');
        $request->headers->set('X-Pos-Web-Client-Auth', 'wrong-secret');
        $request->headers->set('X-Pos-Web-Client-IP', '203.0.113.91');

        $this->assertSame($baseline, $this->resolveIpLimitsForRequest($request));
    }

    public function test_garbage_forwarded_ip_uses_socket_bucket_and_never_unknown(): void
    {
        config(['qr.bff_client_ip_secret' => self::BFF_SECRET]);
        $baseline = $this->resolveIpLimits('198.51.100.42');
        $request = $this->requestFor('198.51.100.42');
        $this->withForwardedIp($request, 'not-an-ip');

        $resolved = $this->resolveIpLimitsForRequest($request);

        $this->assertSame($baseline, $resolved);
        foreach ($resolved as $limit) {
            $this->assertStringNotContainsString('unknown', $limit[0]);
        }
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
            'qr-table-read' => $this->describe($this->limits($rateLimiter, 'qr-table-read', $request)[0]),
            'qr-table-bind' => $this->describe($this->limits($rateLimiter, 'qr-table-bind', $request)[1]),
            'qr-dine-in-round' => $this->describe($this->limits($rateLimiter, 'qr-dine-in-round', $request)[1]),
            'qr-dine-in-finish' => $this->describe($this->limits($rateLimiter, 'qr-dine-in-finish', $request)[1]),
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
            'qr-table-read' => ['qr-table-read:ip:'.$ipKey, 3000, 60],
            'qr-table-bind' => ['qr-table-bind:ip:'.$ipKey, 600, 60],
            'qr-dine-in-round' => ['qr-dine-in-round:ip:'.$ipKey, 400, 60],
            'qr-dine-in-finish' => ['qr-dine-in-finish:ip:'.$ipKey, 200, 60],
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

    private function withForwardedIp(Request $request, string $ip): void
    {
        $request->headers->set('X-Pos-Web-Client-Auth', self::BFF_SECRET);
        $request->headers->set('X-Pos-Web-Client-IP', $ip);
    }
}
