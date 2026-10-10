<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictKitchenDisplay
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->device_type === 'kitchen_display' && ! in_array($request->route()?->getName(), ['kitchen-v2.display.connection', 'kitchen-v2.display.session', 'kitchen-v2.certificate', 'kitchen-v2.snapshot', 'kitchen-v2.events.read', 'kitchen-v2.events.write', 'device.identity', 'device.heartbeat'], true)) {
            return response()->json(['data' => null, 'errors' => [['code' => 'kds_scope_forbidden', 'message' => 'Kitchen display scope only.']]], 403);
        }

        return $next($request);
    }
}
