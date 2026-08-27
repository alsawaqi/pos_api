<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Qr;

use App\Actions\Qr\DistinctQrPhoneGuard;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Sentry\Laravel\Facade as Sentry;
use Sentry\Severity;
use Sentry\State\Scope;
use Tests\TestCase;

class DistinctQrPhoneGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_six_distinct_customers_on_six_sessions_share_one_branch_ip(): void
    {
        $guard = app(DistinctQrPhoneGuard::class);

        for ($customer = 1; $customer <= 6; $customer++) {
            $this->assertTrue($guard->allows(
                'session-'.$customer,
                10,
                '192.0.2.1',
                '9000000'.$customer,
            ));
        }
    }

    public function test_fourth_distinct_phone_on_one_session_is_still_refused(): void
    {
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows('session-a', 10, '192.0.2.1', '90000001'));
        $this->assertTrue($guard->allows('session-a', 10, '192.0.2.2', '90000002'));
        $this->assertTrue($guard->allows('session-a', 10, '192.0.2.3', '90000003'));
        $this->assertFalse($guard->allows('session-a', 10, '192.0.2.4', '90000004'));

        // Replaying one of the three accepted identities consumes no new slot.
        $this->assertTrue($guard->allows('session-a', 10, '192.0.2.5', '90000003'));
    }

    public function test_branches_sharing_one_ip_do_not_consume_each_others_backstop(): void
    {
        config(['qr.distinct_phone_ip_backstop_per_branch_per_hour' => 2]);
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows('branch-10-a', 10, '192.0.2.8', '90000001'));
        $this->assertTrue($guard->allows('branch-10-b', 10, '192.0.2.8', '90000002'));
        $this->assertTrue($guard->allows('branch-20-a', 20, '192.0.2.8', '90000003'));
        $this->assertTrue($guard->allows('branch-20-b', 20, '192.0.2.8', '90000004'));

        $store = Cache::getStore();
        $reflection = new \ReflectionObject($store);
        $property = $reflection->getProperty('storage');
        $keys = implode('|', array_keys($property->getValue($store)));

        $this->assertStringContainsString('branch:10:ip:'.hash('sha256', '192.0.2.8'), $keys);
        $this->assertStringContainsString('branch:20:ip:'.hash('sha256', '192.0.2.8'), $keys);
        $this->assertStringNotContainsString('192.0.2.8', $keys);
        $this->assertStringNotContainsString('90000001', $keys);
        $this->assertStringNotContainsString('90000004', $keys);
    }

    public function test_ip_backstop_refuses_and_emits_sentry_warning_with_branch_and_count(): void
    {
        config(['qr.distinct_phone_ip_backstop_per_branch_per_hour' => 2]);
        $scope = Mockery::mock(Scope::class);
        $scope->shouldReceive('setContext')
            ->once()
            ->with('qr_distinct_phone_ip_backstop', [
                'branch_id' => 10,
                'count' => 3,
            ])
            ->andReturnSelf();
        Sentry::shouldReceive('captureMessage')
            ->once()
            ->with(
                'QR distinct-phone IP backstop reached',
                Mockery::on(static fn (mixed $severity): bool => $severity instanceof Severity
                    && $severity->isEqualTo(Severity::warning())),
            );
        Sentry::shouldReceive('withScope')
            ->once()
            ->andReturnUsing(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $guard = app(DistinctQrPhoneGuard::class);
        $this->assertTrue($guard->allows('session-a', 10, '192.0.2.9', '90000001'));
        $this->assertTrue($guard->allows('session-b', 10, '192.0.2.9', '90000002'));
        $this->assertFalse($guard->allows('session-c', 10, '192.0.2.9', '90000003'));
    }

    public function test_native_ipv6_addresses_share_their_branch_backstop_within_one_64(): void
    {
        config(['qr.distinct_phone_ip_backstop_per_branch_per_hour' => 2]);
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows(
            'ipv6-session-a',
            10,
            '2001:db8:abcd:1234:1111:2222:3333:4444',
            '90000001',
        ));
        $this->assertTrue($guard->allows(
            'ipv6-session-b',
            10,
            '2001:db8:abcd:1234:aaaa:bbbb:cccc:dddd',
            '90000002',
        ));
        $this->assertFalse($guard->allows(
            'ipv6-session-c',
            10,
            '2001:db8:abcd:1234::ffff',
            '90000003',
        ));
        $this->assertTrue($guard->allows(
            'ipv6-session-d',
            10,
            '2001:db8:abcd:1235::1',
            '90000004',
        ));

        $store = Cache::getStore();
        $reflection = new \ReflectionObject($store);
        $property = $reflection->getProperty('storage');
        $keys = implode('|', array_keys($property->getValue($store)));

        $this->assertStringContainsString(
            'branch:10:ip:'.hash('sha256', '2001:db8:abcd:1234::/64'),
            $keys,
        );
        $this->assertStringNotContainsString(
            hash('sha256', '2001:db8:abcd:1234:1111:2222:3333:4444'),
            $keys,
        );
    }

    public function test_ipv4_mapped_ipv6_shares_the_canonical_ipv4_backstop(): void
    {
        config(['qr.distinct_phone_ip_backstop_per_branch_per_hour' => 1]);
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows(
            'mapped-session-a',
            10,
            '::ffff:192.0.2.44',
            '90000001',
        ));
        $this->assertFalse($guard->allows(
            'mapped-session-b',
            10,
            '192.0.2.44',
            '90000002',
        ));
    }

    public function test_malformed_ips_share_one_fixed_backstop_bucket(): void
    {
        config(['qr.distinct_phone_ip_backstop_per_branch_per_hour' => 1]);
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows('invalid-session-a', 10, 'not-an-ip', '90000001'));
        $this->assertFalse($guard->allows('invalid-session-b', 10, 'also-not-an-ip', '90000002'));
    }
}
