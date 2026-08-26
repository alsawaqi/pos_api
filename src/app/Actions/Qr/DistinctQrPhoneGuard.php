<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Limits each session and client IP to three distinct phone hashes per hour. */
final class DistinctQrPhoneGuard
{
    public function allows(string $sessionUuid, string $ip, string $phone): bool
    {
        $hour = now()->format('YmdH');
        $phoneHash = hash('sha256', $phone);
        $axes = [
            'session:'.hash('sha256', $sessionUuid),
            'ip:'.hash('sha256', $ip),
        ];

        foreach ($axes as $axis) {
            $key = 'qr:distinct-phones:'.$axis.':'.$hour;

            try {
                $allowed = Cache::lock($key.':lock', 5)->block(2, function () use ($key, $phoneHash): bool {
                    /** @var list<string> $hashes */
                    $hashes = array_values(array_filter(
                        (array) Cache::get($key, []),
                        static fn (mixed $value): bool => is_string($value),
                    ));

                    if (in_array($phoneHash, $hashes, true)) {
                        return true;
                    }
                    if (count($hashes) >= 3) {
                        return false;
                    }

                    $hashes[] = $phoneHash;
                    Cache::put($key, $hashes, now()->endOfHour()->addSecond());

                    return true;
                });
            } catch (LockTimeoutException) {
                // The identity surface fails closed when its accounting lock
                // is congested rather than granting an untracked attempt.
                return false;
            }

            if (! $allowed) {
                return false;
            }
        }

        return true;
    }
}
