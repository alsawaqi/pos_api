<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * PHASE-1A D-3 / D-5 — the escalating per-device PIN lockout.
 *
 * Two independent scopes, each with its own counter and lock:
 *   login    POST /auth/pos/login
 *   manager  every manager-PIN check (verify-manager-pin, unlock-pin-lock and
 *            the six PIN-checked actions) and the kitchen walk-up PIN, which
 *            also answers whether a PIN is valid
 *
 * Rules: the 5th CONSECUTIVE wrong PIN locks the scope on that device for
 * 60 s (that attempt already answers 423). The lock is evaluated BEFORE the
 * PIN is checked, so even a correct PIN gets 423 while locked; every attempt
 * during a lock doubles the window (capped at 15 min) from that moment. The
 * counter lives 15 minutes after the last failure (rolling), so a device that
 * fails twice a day never accumulates a lock. A correct PIN clears the
 * scope's counter. A manager unlock (D-7) clears the LOGIN scope.
 *
 * Accepted model (D-3): the counter is per device, and because login carries
 * no identity, any correct PIN of the branch clears it. Cache-backed (Redis
 * in production); a cache flush clears every lock.
 */
final class PinLockout
{
    public const LOGIN = 'login';

    public const MANAGER = 'manager';

    public const THRESHOLD = 5;

    public const FIRST_LOCK_SECONDS = 60;

    public const MAX_LOCK_SECONDS = 900;

    public const COUNTER_TTL_SECONDS = 900;

    /** Refuse (423) when the scope is locked; each refused attempt doubles the lock. */
    public function guard(Device $device, string $scope): void
    {
        if ($this->remaining($device, $scope) === 0) {
            return;
        }
        $locked = $this->mutate($device, $scope, function (array $state): array {
            $now = now()->getTimestamp();
            if (($state['locked_until'] ?? 0) <= $now) {
                return [$state, null];
            }
            $window = min(max((int) ($state['window'] ?? self::FIRST_LOCK_SECONDS), self::FIRST_LOCK_SECONDS) * 2, self::MAX_LOCK_SECONDS);
            $state['window'] = $window;
            $state['locked_until'] = $now + $window;

            return [$state, $window];
        });

        if ($locked !== null) {
            throw new PinLockedException($locked, $scope);
        }
    }

    /** Count a wrong PIN; the THRESHOLD-th consecutive one locks the scope (423). */
    public function failed(Device $device, string $scope): void
    {
        $locked = $this->mutate($device, $scope, function (array $state): array {
            $now = now()->getTimestamp();
            $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
            if ($state['failures'] < self::THRESHOLD) {
                return [$state, null];
            }
            $previous = (int) ($state['window'] ?? 0);
            $window = $previous === 0 ? self::FIRST_LOCK_SECONDS : min($previous * 2, self::MAX_LOCK_SECONDS);
            $state['window'] = $window;
            $state['locked_until'] = $now + $window;

            return [$state, $window];
        });

        if ($locked !== null) {
            throw new PinLockedException($locked, $scope);
        }
    }

    /** A correct PIN clears the scope's counter and lock. */
    public function succeeded(Device $device, string $scope): void
    {
        $this->clear($device, $scope);
    }

    public function clear(Device $device, string $scope): void
    {
        Cache::forget($this->key($device, $scope));
    }

    /** Seconds left on the scope's lock, or 0. */
    public function remaining(Device $device, string $scope): int
    {
        $state = Cache::get($this->key($device, $scope));

        return is_array($state) ? max(0, (int) ($state['locked_until'] ?? 0) - now()->getTimestamp()) : 0;
    }

    /**
     * @param  callable(array<string, int>): array{0: array<string, int>, 1: ?int}  $change
     */
    private function mutate(Device $device, string $scope, callable $change): ?int
    {
        $key = $this->key($device, $scope);

        return Cache::lock($key.':mutex', 5)->block(3, function () use ($key, $change): ?int {
            $state = Cache::get($key);
            [$state, $locked] = $change(is_array($state) ? $state : []);
            $lockLeft = max(0, (int) ($state['locked_until'] ?? 0) - now()->getTimestamp());
            Cache::put($key, $state, self::COUNTER_TTL_SECONDS + $lockLeft);

            return $locked;
        });
    }

    private function key(Device $device, string $scope): string
    {
        return 'pin-lockout:'.$scope.':'.(int) $device->getKey();
    }
}
