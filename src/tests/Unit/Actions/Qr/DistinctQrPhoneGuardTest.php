<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Qr;

use App\Actions\Qr\DistinctQrPhoneGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DistinctQrPhoneGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_fourth_distinct_phone_is_refused_per_session_and_ip(): void
    {
        $guard = app(DistinctQrPhoneGuard::class);

        $this->assertTrue($guard->allows('session-a', '192.0.2.1', '90000001'));
        $this->assertTrue($guard->allows('session-a', '192.0.2.1', '90000002'));
        $this->assertTrue($guard->allows('session-a', '192.0.2.1', '90000003'));
        $this->assertFalse($guard->allows('session-a', '192.0.2.1', '90000004'));

        // Replaying one of the three accepted identities consumes no new slot.
        $this->assertTrue($guard->allows('session-a', '192.0.2.1', '90000003'));
    }

    public function test_both_axes_are_enforced_without_raw_pii_cache_keys(): void
    {
        $guard = app(DistinctQrPhoneGuard::class);

        foreach (['90000001', '90000002', '90000003'] as $index => $phone) {
            $this->assertTrue($guard->allows('session-'.($index + 1), '192.0.2.8', $phone));
        }

        $this->assertFalse($guard->allows('session-new', '192.0.2.8', '90000004'));

        Cache::flush();
        $this->assertTrue($guard->allows('session-fixed', '192.0.2.11', '90000001'));
        $this->assertTrue($guard->allows('session-fixed', '192.0.2.12', '90000002'));
        $this->assertTrue($guard->allows('session-fixed', '192.0.2.13', '90000003'));
        $this->assertFalse($guard->allows('session-fixed', '192.0.2.14', '91111111'));

        $store = Cache::getStore();
        $reflection = new \ReflectionObject($store);
        $property = $reflection->getProperty('storage');
        $keys = implode('|', array_keys($property->getValue($store)));

        $this->assertStringNotContainsString('90000001', $keys);
        $this->assertStringNotContainsString('91111111', $keys);
    }
}
