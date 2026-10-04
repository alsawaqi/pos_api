<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\AuthorizationGate;
use App\Support\Staff\StaffToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P5 fix order 1 (F1) — the online P5 endpoints a staff member works
 * through (the table operations, the sold-out switch and the shift read)
 * take the logged-in person's signed staff token in the `X-Staff-Token`
 * header ({@see StaffToken}). For a P5 request from a till or a handheld the
 * token must be valid, issued to this device, name the request's own
 * `staff_id` (when it names one) and that person must still be active; else
 *
 *   403 { data: { reason }, errors: [{ code: "staff_unverified", message }] }
 *
 * reason: token_missing | token_invalid | token_other_device |
 * token_other_staff | staff_inactive. An old build's request (no auth_v)
 * keeps today's behaviour. The checked token rides on as a request
 * attribute for the authorization gate; the attributes also tell the gate
 * the request came online (not through the sync outbox).
 */
final class RequireStaffToken
{
    /** Request attribute: true on the online endpoints this guards. */
    public const ONLINE = 'p5.online';

    /** Request attribute: the X-Staff-Token header as sent (or null). */
    public const TOKEN = 'p5.staff_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header(StaffToken::HEADER);
        $token = is_string($token) && $token !== '' ? $token : null;
        $request->attributes->set(self::ONLINE, true);
        $request->attributes->set(self::TOKEN, $token);

        $device = $request->user();
        if (! $device instanceof Device || ! $device->isAttended() || ! AuthorizationGate::isP5($request->all(), $device)) {
            return $next($request);
        }

        $staffId = $request->input('staff_id');
        $staffId = is_int($staffId) || (is_string($staffId) && ctype_digit($staffId)) ? (int) $staffId : null;
        $check = StaffToken::check($device, $token, $staffId);
        $reason = $check['failure'];
        if ($reason === null && ! PosStaff::query()->where('company_id', $device->company_id)
            ->whereKey($check['staff_id'])->where('status', PosStaff::STATUS_ACTIVE)->exists()) {
            $reason = 'staff_inactive';
        }
        if ($reason !== null) {
            return response()->json(['data' => ['reason' => $reason], 'errors' => [[
                'code' => 'staff_unverified',
                'message' => 'Log in again to do this.',
            ]]], 403);
        }

        return $next($request);
    }
}
