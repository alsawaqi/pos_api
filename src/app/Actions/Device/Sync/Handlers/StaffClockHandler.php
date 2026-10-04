<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\SyncRefusal;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\Device;
use App\Models\SyncEvent;
use App\Support\Staff\AttendanceBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * LAUNCH-P5 (A7) — the `staff.clock_in` and `staff.clock_out` sync events:
 *
 *   payload { attendance_uuid, staff_id, at, auth_v: 1 }
 *
 * through the outbox, idempotent by attendance_uuid ({@see AttendanceBook}).
 * One open attendance per person (a second clock-in returns the open one);
 * a clock-out with none open creates a row flagged no_clock_in. The staff
 * member must belong to the device's company (an inactive one is flagged).
 */
final class StaffClockHandler implements SyncEventHandler
{
    public function __construct(private readonly AttendanceBook $book) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        // LAUNCH-P5 fix order 1 (F8) — attendance is recorded on a till or a
        // handheld only; anything else is refused for good.
        if (! $device->isAttended()) {
            throw new SyncRefusal('device_not_attended', 'Only a till or a handheld can record attendance.', permanent: true);
        }
        $payload = (array) $event->payload_json;
        $validator = Validator::make($payload, [
            'attendance_uuid' => ['required', 'uuid'],
            'staff_id' => ['required', 'integer', 'min:1'],
            'at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid '.$event->event_type.' payload: '.implode('; ', $validator->errors()->all()));
        }
        $staffId = (int) $payload['staff_id'];
        TenantReferenceGuard::assertStaffInTenant($device, $staffId, $event->event_type.' references a staff member outside the device tenant');
        $at = Carbon::parse((string) $payload['at'])->utc();

        return $event->event_type === 'staff.clock_out'
            ? $this->book->clockOut($device, $staffId, (string) $payload['attendance_uuid'], $at)
            : $this->book->clockIn($device, $staffId, (string) $payload['attendance_uuid'], $at);
    }
}
