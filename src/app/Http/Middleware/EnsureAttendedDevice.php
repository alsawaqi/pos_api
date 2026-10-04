<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P5 fix order 1 (F8) — staff PIN routes and the approver material are
 * served to a till (`fixed_pos`) or a handheld only: the staff login, the
 * manager-PIN check, the PIN-lock unlock, `/device/approvers` (every
 * approver's salt / iterations / check) and `/device/staff-status`. A
 * customer tablet or a payment station faces the customer, so anyone holding
 * its token could brute-force PINs offline from the verifier material:
 *
 *   403 { data: null, errors: [{ code: "device_not_attended", message }] }
 *
 * The attendance sync events are refused the same way by StaffClockHandler.
 */
final class EnsureAttendedDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || ! $device->isAttended()) {
            return response()->json(['data' => null, 'errors' => [[
                'code' => 'device_not_attended',
                'message' => 'Only a till or a handheld can do this.',
            ]]], 403);
        }

        return $next($request);
    }
}
