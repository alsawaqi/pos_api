<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\Device;
use App\Models\StaffAttendance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 — clock in / clock out (owner decision 6).
 *
 *  - One open attendance per person: a second clock-in returns the open one.
 *  - An open row older than 16 h is flagged "no_clock_out" and no longer
 *    counts as open (the person clocks in afresh); the Hours report shows it.
 *  - A clock-out closes the open row (or the row of its attendance_uuid); a
 *    clock-out with nothing open creates a zero-length row flagged
 *    "no_clock_in".
 *  - Idempotent by attendance_uuid (the sync ledger already dedupes a
 *    re-pushed event; a new event for the same uuid finds its row).
 *
 * "Same day" for the device's first-login prompt is the device's concern; the
 * server only reports whether an open attendance exists.
 */
final class AttendanceBook
{
    public const STALE_HOURS = 16;

    public const FLAG_NO_CLOCK_OUT = 'no_clock_out';

    public const FLAG_NO_CLOCK_IN = 'no_clock_in';

    /** The person's open attendance (stale rows are flagged first), or null. */
    public function openFor(int $companyId, int $staffId, ?CarbonInterface $at = null): ?StaffAttendance
    {
        $at ??= now();
        $this->flagStale($companyId, $staffId, $at);

        return $this->openQuery($companyId, $staffId)->orderByDesc('clock_in_at')->orderByDesc('id')->first();
    }

    /** @return array{open: bool, clock_in_at?: string, attendance_uuid?: string} */
    public function state(int $companyId, int $staffId): array
    {
        $open = $this->openFor($companyId, $staffId);

        return $open === null
            ? ['open' => false]
            : ['open' => true, 'clock_in_at' => $open->clock_in_at->copy()->utc()->toIso8601String(), 'attendance_uuid' => (string) $open->uuid];
    }

    /** @return array<string, mixed> */
    public function clockIn(Device $device, int $staffId, string $uuid, Carbon $at): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid, $at): array {
            $this->lockStaff($staffId);
            $companyId = (int) $device->company_id;
            $own = StaffAttendance::query()->where('company_id', $companyId)->where('uuid', $uuid)->first();
            if ($own !== null) {
                return $this->result($own, 'already_recorded');
            }
            $open = $this->openFor($companyId, $staffId, $at);
            if ($open !== null) {
                return $this->result($open, 'already_open');
            }
            $row = StaffAttendance::query()->create([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'branch_id' => (int) $device->branch_id,
                'staff_id' => $staffId,
                'device_id' => (int) $device->getKey(),
                'clock_in_at' => $at,
                'source' => StaffAttendance::SOURCE_DEVICE,
            ]);

            return $this->result($row, 'clocked_in');
        });
    }

    /** @return array<string, mixed> */
    public function clockOut(Device $device, int $staffId, string $uuid, Carbon $at): array
    {
        return DB::transaction(function () use ($device, $staffId, $uuid, $at): array {
            $this->lockStaff($staffId);
            $companyId = (int) $device->company_id;
            $row = StaffAttendance::query()->where('company_id', $companyId)->where('staff_id', $staffId)
                ->where(fn ($q) => $q->where('uuid', $uuid)->orWhere('flags->clock_out_uuid', $uuid))
                ->first();
            if ($row !== null && $row->clock_out_at !== null) {
                return $this->result($row, 'already_recorded');
            }
            // The device's own row, else the latest row still without a
            // clock-out (a stale one included: this IS its clock-out).
            $row ??= StaffAttendance::query()->where('company_id', $companyId)->where('staff_id', $staffId)
                ->whereNull('clock_out_at')->where('clock_in_at', '<=', $at)
                ->orderByDesc('clock_in_at')->orderByDesc('id')->first();
            if ($row === null) {
                $row = StaffAttendance::query()->create([
                    'uuid' => $uuid,
                    'company_id' => $companyId,
                    'branch_id' => (int) $device->branch_id,
                    'staff_id' => $staffId,
                    'device_id' => (int) $device->getKey(),
                    'clock_in_at' => $at,
                    'clock_out_at' => $at,
                    'source' => StaffAttendance::SOURCE_DEVICE,
                    'flags' => [self::FLAG_NO_CLOCK_IN => true],
                ]);

                return $this->result($row, 'flagged');
            }
            $flags = (array) ($row->flags ?? []);
            if ((string) $row->uuid !== $uuid) {
                $flags['clock_out_uuid'] = $uuid;
            }
            $row->update([
                'clock_out_at' => $at->lt($row->clock_in_at) ? $row->clock_in_at : $at,
                'flags' => $flags === [] ? null : $flags,
            ]);

            return $this->result($row->refresh(), 'clocked_out');
        });
    }

    private function openQuery(int $companyId, int $staffId)
    {
        return StaffAttendance::query()->where('company_id', $companyId)->where('staff_id', $staffId)
            ->whereNull('clock_out_at')
            ->where(fn ($q) => $q->whereNull('flags')->orWhereNull('flags->'.self::FLAG_NO_CLOCK_OUT));
    }

    private function flagStale(int $companyId, int $staffId, CarbonInterface $at): void
    {
        $stale = $this->openQuery($companyId, $staffId)
            ->where('clock_in_at', '<', Carbon::instance($at)->subHours(self::STALE_HOURS))
            ->get();
        foreach ($stale as $row) {
            $row->update(['flags' => [...(array) ($row->flags ?? []), self::FLAG_NO_CLOCK_OUT => true]]);
        }
    }

    /** Serialize one person's clock events (one open attendance per person). */
    private function lockStaff(int $staffId): void
    {
        DB::table('pos_staff')->where('id', $staffId)->lockForUpdate()->first();
    }

    /** @return array<string, mixed> */
    private function result(StaffAttendance $row, string $status): array
    {
        return [
            'status' => $status,
            'attendance_uuid' => (string) $row->uuid,
            'staff_id' => (int) $row->staff_id,
            'clock_in_at' => $row->clock_in_at->copy()->utc()->toIso8601String(),
            'clock_out_at' => $row->clock_out_at?->copy()->utc()->toIso8601String(),
            'flags' => $row->flags ?? [],
        ];
    }
}
