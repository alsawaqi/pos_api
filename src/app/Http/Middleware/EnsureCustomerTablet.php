<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P6 — the `device/tablet/*` routes serve an active, assigned customer
 * tablet only:
 *
 *   403 { data: null, errors: [{ code: "device_not_tablet", message }] }
 */
final class EnsureCustomerTablet
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || $device->device_type !== 'customer_tablet'
            || $device->status !== 'active' || ! $device->isAssigned()) {
            return response()->json(['data' => null, 'errors' => [[
                'code' => 'device_not_tablet',
                'message' => 'Only an active customer tablet can do this.',
            ]]], 403);
        }

        return $next($request);
    }
}
