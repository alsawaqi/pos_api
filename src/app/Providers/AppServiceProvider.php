<?php

namespace App\Providers;

use App\Models\Device;
use App\Support\QrApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 8 — the device guard. A paired terminal authenticates by
        // presenting its long-lived device_token as a Bearer credential;
        // we resolve it straight off the shared pos_devices table. No
        // Sanctum — the token is a column, not a personal-access-token.
        // SoftDeletes excludes deleted rows; the status filter additionally
        // rejects REVOKED devices whose token column is still set — 'blocked'
        // (decommissioned/suspended by the admin) and 'inactive' (disabled).
        // A paired, operable device is 'active'; pre-pairing states
        // ('registered'/'assigned') carry no token so never reach here.
        // Statuses mirror pos_admin's DeviceStatus enum.
        Auth::viaRequest('pos_device', function (Request $request): ?Device {
            $token = $request->bearerToken();
            if ($token === null || $token === '') {
                return null;
            }

            return Device::query()
                ->where('device_token', $token)
                ->whereNotIn('status', ['blocked', 'inactive'])
                ->first();
        });

        // Rate limiters (blueprint §12 hardening). Pairing is the brute-force
        // surface — an attacker guessing one-time activation tokens — so it is
        // throttled hard, both per-IP and per-kiosk. Authenticated device
        // traffic (heartbeat, sync) gets a generous per-device budget keyed
        // off the resolved device id so one noisy terminal can't starve the
        // rest of the fleet (and falls back to IP before the guard resolves).
        RateLimiter::for('device-pair', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.self::rateLimitIpKey($request->ip())),
            Limit::perMinute(20)->by('kiosk:'.(string) $request->input('kiosk_id')),
        ]);

        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute(120)
            ->by('device:'.(string) ($request->user()?->getAuthIdentifier() ?? self::rateLimitIpKey($request->ip()))));

        $qrRateLimited = static function (Request $request, array $headers) {
            $response = QrApiResponse::failure('rate_limited', 'Too many requests.', 429);
            $response->headers->add($headers);

            return $response;
        };

        RateLimiter::for('qr-bind', function (Request $request) use ($qrRateLimited): array {
            $submittedToken = $request->input('token');
            $token = is_string($submittedToken) ? $submittedToken : '';

            return [
                Limit::perMinute(10)
                    ->by('qr-bind:ip:'.self::rateLimitIpKey($request->ip()))
                    ->response($qrRateLimited),
                Limit::perMinute(10)
                    ->by('qr-bind:token:'.hash('sha256', $token))
                    ->response($qrRateLimited),
            ];
        });

        // Credentialed session buckets are the primary controls. The much
        // higher IP ceilings are anonymous resource backstops kept generous
        // enough that ordinary customers behind shared NAT do not collide.
        RateLimiter::for('qr-read', fn (Request $request) => [
            Limit::perMinute(60)
                ->by('qr-read:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
            Limit::perMinute(3000)
                ->by('qr-read:ip:'.self::rateLimitIpKey($request->ip()))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-quote', fn (Request $request) => [
            Limit::perMinute(30)
                ->by('qr-quote:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
            Limit::perMinute(600)
                ->by('qr-quote:ip:'.self::rateLimitIpKey($request->ip()))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-checkout', fn (Request $request) => [
            Limit::perMinute(10)
                ->by('qr-checkout:ip:'.self::rateLimitIpKey($request->ip()))
                ->response($qrRateLimited),
            Limit::perMinute(10)
                ->by('qr-checkout:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
        ]);

        // POS staff PIN login is a 6-digit brute-force surface — throttle it
        // hard per-device (the device is already resolved by the guard before
        // this limiter runs), independent of the generous device-api budget.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(10)
            ->by('pos-login:'.(string) ($request->user()?->getAuthIdentifier() ?? self::rateLimitIpKey($request->ip()))));
    }

    /**
     * Keep one bucket per IPv4 address and one per native IPv6 /64.
     *
     * IPv4-mapped IPv6 must retain IPv4 cardinality; treating it as native
     * IPv6 would collapse every mapped IPv4 address into the same ::/64.
     */
    private static function rateLimitIpKey(?string $ip): string
    {
        $packed = inet_pton((string) $ip);
        if ($packed === false) {
            return 'unknown';
        }

        if (strlen($packed) === 4) {
            $normalized = inet_ntop($packed);

            return $normalized === false ? 'unknown' : $normalized;
        }

        $mappedIpv4Prefix = str_repeat("\0", 10)."\xff\xff";
        if (str_starts_with($packed, $mappedIpv4Prefix)) {
            $normalized = inet_ntop(substr($packed, 12, 4));

            return $normalized === false ? 'unknown' : $normalized;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $network === false ? 'unknown' : $network.'/64';
    }
}
