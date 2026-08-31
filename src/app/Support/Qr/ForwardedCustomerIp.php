<?php

declare(strict_types=1);

namespace App\Support\Qr;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

/** Resolves the customer IP asserted by the authenticated POS web BFF. */
final class ForwardedCustomerIp
{
    public const AUTH_HEADER = 'X-Pos-Web-Client-Auth';

    public const IP_HEADER = 'X-Pos-Web-Client-IP';

    private const WARNING_TTL_SECONDS = 300;

    public function resolve(Request $request): string
    {
        $fallback = (string) ($request->ip() ?? '');
        $configuredSecret = config('qr.bff_client_ip_secret');

        if (! is_string($configuredSecret) || $configuredSecret === '') {
            $this->warnIgnored('secret_unconfigured');

            return $fallback;
        }

        $providedSecret = $request->header(self::AUTH_HEADER);
        if (! is_string($providedSecret) || $providedSecret === '') {
            $this->warnIgnored('auth_missing');

            return $fallback;
        }

        if (! hash_equals($configuredSecret, $providedSecret)) {
            $this->warnIgnored('auth_mismatch');

            return $fallback;
        }

        $forwardedIp = $request->header(self::IP_HEADER);
        if (! is_string($forwardedIp) || $forwardedIp === '') {
            $this->warnIgnored('forwarded_ip_missing');

            return $fallback;
        }

        if (@inet_pton($forwardedIp) === false) {
            $this->warnIgnored('forwarded_ip_invalid');

            return $fallback;
        }

        return $forwardedIp;
    }

    private function warnIgnored(string $reason): void
    {
        try {
            if (! Cache::add('qr:bff-client-ip-warning:'.$reason, true, self::WARNING_TTL_SECONDS)) {
                return;
            }

            Log::warning('QR BFF customer IP headers ignored', ['reason' => $reason]);
            Sentry::withScope(static function (Scope $scope) use ($reason): void {
                $scope->setContext('qr_bff_client_ip', ['reason' => $reason]);
                Sentry::captureMessage('QR BFF customer IP headers ignored', Severity::warning());
            });
        } catch (Throwable) {
            // Attribution remains fail-safe if cache, logging, or telemetry fails.
        }
    }
}
