<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Sentry\Laravel\Facade as Sentry;
use Sentry\Severity;
use Sentry\State\Scope;

/** Keeps a tight session identity limit plus a branch-scoped IP automation backstop. */
final class DistinctQrPhoneGuard
{
    private const SESSION_LIMIT = 3;

    public function allows(string $sessionUuid, int $branchId, string $ip, string $phone): bool
    {
        $hour = now()->format('YmdH');
        $phoneHash = hash('sha256', $phone);

        $session = $this->check(
            'qr:distinct-phones:session:'.hash('sha256', $sessionUuid).':'.$hour,
            $phoneHash,
            self::SESSION_LIMIT,
        );
        if (! $session['allowed']) {
            return false;
        }

        $ipLimit = max(1, (int) config('qr.distinct_phone_ip_backstop_per_branch_per_hour', 500));
        $ipBackstop = $this->check(
            'qr:distinct-phones:branch:'.$branchId.':ip:'.hash('sha256', $ip).':'.$hour,
            $phoneHash,
            $ipLimit,
        );
        if (! $ipBackstop['allowed']) {
            if ($ipBackstop['limit_exceeded']) {
                $this->warnIpBackstopReached($branchId, $ipBackstop['count']);
            }

            return false;
        }

        return true;
    }

    /**
     * @return array{allowed: bool, count: int, limit_exceeded: bool}
     */
    private function check(string $key, string $phoneHash, int $limit): array
    {
        try {
            return Cache::lock($key.':lock', 5)->block(2, function () use ($key, $phoneHash, $limit): array {
                /** @var list<string> $hashes */
                $hashes = array_values(array_filter(
                    (array) Cache::get($key, []),
                    static fn (mixed $value): bool => is_string($value),
                ));
                $storedCount = count($hashes);

                if (in_array($phoneHash, $hashes, true)) {
                    return ['allowed' => true, 'count' => $storedCount, 'limit_exceeded' => false];
                }

                $attemptedCount = $storedCount + 1;
                if ($storedCount >= $limit) {
                    return ['allowed' => false, 'count' => $attemptedCount, 'limit_exceeded' => true];
                }

                $hashes[] = $phoneHash;
                Cache::put($key, $hashes, now()->endOfHour()->addSecond());

                return ['allowed' => true, 'count' => $attemptedCount, 'limit_exceeded' => false];
            });
        } catch (LockTimeoutException) {
            // The identity surface fails closed when its accounting lock
            // is congested rather than granting an untracked attempt.
            return ['allowed' => false, 'count' => 0, 'limit_exceeded' => false];
        }
    }

    private function warnIpBackstopReached(int $branchId, int $count): void
    {
        Sentry::withScope(static function (Scope $scope) use ($branchId, $count): void {
            $scope->setContext('qr_distinct_phone_ip_backstop', [
                'branch_id' => $branchId,
                'count' => $count,
            ]);
            Sentry::captureMessage('QR distinct-phone IP backstop reached', Severity::warning());
        });
    }
}
