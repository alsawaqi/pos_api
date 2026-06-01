<?php

namespace App\Providers;

use App\Models\Device;
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
            Limit::perMinute(10)->by('ip:'.(string) $request->ip()),
            Limit::perMinute(20)->by('kiosk:'.(string) $request->input('kiosk_id')),
        ]);

        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute(120)
            ->by('device:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // POS staff PIN login is a 6-digit brute-force surface — throttle it
        // hard per-device (the device is already resolved by the guard before
        // this limiter runs), independent of the generous device-api budget.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(10)
            ->by('pos-login:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
