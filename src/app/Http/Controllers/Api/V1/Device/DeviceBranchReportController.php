<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\BuildBranchReportAction;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * P-F6 — the device's full-screen branch Reports dashboard.
 *
 *   GET /api/v1/device/reports/branch?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Branch + company scoped to the calling device; paid orders only; all
 * money integer baisas. Who may OPEN the dashboard is the merchant's
 * `reports_positions` company setting — emitted in /device/config and
 * enforced by the DEVICE (the report itself carries no PII beyond what
 * the terminal already caches).
 *
 * from/to are parsed defensively: a malformed or inconsistent window
 * falls back to the last 7 days (to = today, from = to − 6 days) and the
 * span is capped at 92 days, so a bad query string can never 500 or sweep
 * the whole table.
 */
class DeviceBranchReportController
{
    public function __invoke(Request $request, BuildBranchReportAction $report): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        [$from, $to] = $this->resolveWindow($request->query('from'), $request->query('to'));

        return response()->json([
            'data' => ['report' => $report->handle($device, $from, $to)],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }

    /**
     * Resolve the report window: to defaults today, from defaults to−6
     * days; an inverted window (from > to) falls back the same way; the
     * span is capped at 92 days (from clamped up).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(mixed $fromRaw, mixed $toRaw): array
    {
        $to = $this->parseDay($toRaw) ?? today();
        $from = $this->parseDay($fromRaw) ?? $to->copy()->subDays(6);

        if ($from->gt($to)) {
            $from = $to->copy()->subDays(6);
        }
        if ($from->diffInDays($to) > 91) {
            $from = $to->copy()->subDays(91);
        }

        return [$from, $to];
    }

    /**
     * Strict YYYY-MM-DD parse. The round-trip check rejects rollover
     * artifacts (2026-02-30 → Mar 2) and partial/garbage values — any
     * rejection simply falls back to the default window.
     */
    private function parseDay(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }

        if ($day === false || $day->format('Y-m-d') !== trim($value)) {
            return null;
        }

        return $day->startOfDay();
    }
}
