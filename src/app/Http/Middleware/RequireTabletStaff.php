<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\StaffBranches;
use App\Support\Staff\StaffToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P6 — the staff side of the customer tablet (`device/tablet-orders*`)
 * is new, so it has no old-build fallback: every call is made by a till or a
 * handheld (EnsureAttendedDevice runs first) for a logged-in staff member,
 * whose signed X-Staff-Token ({@see StaffToken}) must be valid, issued to
 * this device, and name an active staff member of the device's merchant who
 * works at its branch. Otherwise
 *
 *   403 { data: { reason }, errors: [{ code: "staff_unverified", message }] }
 *
 * reason: token_missing | token_invalid | token_other_device | staff_inactive.
 * The checked staff id rides on as the request attribute {@see self::STAFF}.
 */
final class RequireTabletStaff
{
    public const STAFF = 'p6.staff_id';

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        $token = $request->header(StaffToken::HEADER);
        $check = $device instanceof Device
            ? StaffToken::check($device, is_string($token) && $token !== '' ? $token : null, null)
            : ['staff_id' => null, 'failure' => StaffToken::INVALID];
        $reason = $check['failure'];
        if ($reason === null && ! (PosStaff::query()->where('company_id', $device->company_id)
            ->whereKey($check['staff_id'])->where('status', PosStaff::STATUS_ACTIVE)->exists()
            && StaffBranches::staffWorksAt((int) $check['staff_id'], (int) $device->branch_id))) {
            $reason = 'staff_inactive';
        }
        if ($reason !== null) {
            return response()->json(['data' => ['reason' => $reason], 'errors' => [[
                'code' => 'staff_unverified',
                'message' => 'Log in again to do this.',
            ]]], 403);
        }
        $request->attributes->set(self::STAFF, (int) $check['staff_id']);

        return $next($request);
    }
}
