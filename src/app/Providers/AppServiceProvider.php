<?php

namespace App\Providers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // SoftDeletes on the Device model auto-excludes decommissioned rows.
        Auth::viaRequest('pos_device', function (Request $request): ?Device {
            $token = $request->bearerToken();
            if ($token === null || $token === '') {
                return null;
            }

            return Device::query()
                ->where('device_token', $token)
                ->first();
        });
    }
}
