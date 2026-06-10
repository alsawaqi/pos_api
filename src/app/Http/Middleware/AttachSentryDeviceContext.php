<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * Phase D1 — attaches per-request DEVICE context to Sentry's active scope
 * (the pos_api twin of pos_admin's AttachSentryContext): which device, in
 * which tenant/branch, on which request — so a Sentry alert from the device
 * API is attributable without grepping logs.
 *
 * What gets attached:
 *   - user.id / user.username — the pos_devices id + kiosk code, for the
 *     "all errors from this terminal" filter.
 *   - tags device_id / company_id / branch_id — company_id matches the tag
 *     name pos_admin uses, so cross-app tenant filtering lines up.
 *   - tag request_id — generated/honoured X-Request-Id, echoed on the
 *     response so support can paste it into Sentry's search.
 *
 * The device resolves through the `pos_device` guard explicitly (the lazy
 * viaRequest closure runs on demand; group middleware executes before the
 * route-level auth middleware). Unauthenticated routes (pair/activate) just
 * get the request_id tag. A complete no-op (apart from the trivial scope
 * mutation) when SENTRY_LARAVEL_DSN is empty — the hub is a NullHub.
 */
class AttachSentryDeviceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?: bin2hex(random_bytes(8)));
        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);

        configureScope(function (\Sentry\State\Scope $scope) use ($request, $requestId): void {
            $device = $request->user('pos_device');
            if ($device instanceof Device) {
                $scope->setUser([
                    'id' => $device->getKey(),
                    'username' => (string) ($device->kiosk_id ?? $device->serial_number ?? $device->uuid),
                ]);
                $scope->setTag('device_id', (string) $device->getKey());
                if ($device->company_id !== null) {
                    $scope->setTag('company_id', (string) $device->company_id);
                }
                if ($device->branch_id !== null) {
                    $scope->setTag('branch_id', (string) $device->branch_id);
                }
            }

            $scope->setTag('request_id', $requestId);
        });

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
