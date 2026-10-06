<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P6 (tester call 1) — a customer tablet's device token is
 * allowlisted. The tablet is handed to customers, so its token may reach only
 * its own `device/tablet/*` routes and the heartbeat / identity status routes;
 * everything else on the device API answers
 *
 *   403 { data: null, errors: [{ code: "device_not_allowed_for_tablet", message }] }
 *
 * This includes `device/config` (every customer's phone and points, cost
 * prices, recipes, staff data), `device/customers/*`, sync, orders,
 * `device/qr/*` and the tables. A route with no name is refused too
 * (deny by default). Other device types are not affected.
 */
final class RestrictCustomerTablet
{
    /** Route names a customer tablet may call besides `device.tablet.*`. */
    public const ALLOWED = ['device.heartbeat', 'device.identity'];

    public const TABLET_PREFIX = 'device.tablet.';

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || $device->device_type !== 'customer_tablet') {
            return $next($request);
        }
        $name = (string) ($request->route()?->getName() ?? '');
        if ($name !== '' && (in_array($name, self::ALLOWED, true) || str_starts_with($name, self::TABLET_PREFIX))) {
            return $next($request);
        }

        return response()->json(['data' => null, 'errors' => [[
            'code' => 'device_not_allowed_for_tablet',
            'message' => 'A customer tablet cannot use this.',
        ]]], 403);
    }
}
