<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\Staff\AuthorizationGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P5 fix order 1 (F2, review H2) — the P5 marker is sticky per
 * device. The first online call carrying `auth_v: 1` (body or query) stamps
 * pos_devices.auth_v_seen_at; from then on every request from that device is
 * treated as a P5 build's ({@see AuthorizationGate::isP5()}), so leaving
 * auth_v out no longer falls back to the old rules. Sync events are marked
 * per event by IngestSyncEventsAction.
 */
final class MarkP5Device
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if ($device instanceof Device) {
            AuthorizationGate::observe($device, $request->all());
        }

        return $next($request);
    }
}
