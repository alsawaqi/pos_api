<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\Production\ApplyDispositionAction;
use App\Actions\Device\Production\ComputeExpiringStockAction;
use App\Events\DeviceSyncBroadcast;
use App\Http\Requests\Api\V1\Device\ApplyDispositionRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * P-G1.5 — day-end disposition of expired cooked pieces (ONLINE-ONLY).
 *
 *   GET  /device/disposition  what expires today at this branch (FIFO
 *                             virtual lots — see ComputeExpiringStockAction)
 *   POST /device/disposition  the closer's decision per product: waste /
 *                             manager-approved give-away with a required
 *                             comment / audited carry-over
 *
 * The device runs this step right before shift close; offline it skips
 * (the expired stock simply waits for the next online close).
 */
class DeviceDispositionController
{
    public function __construct(
        private readonly ComputeExpiringStockAction $compute,
        private readonly ApplyDispositionAction $apply,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        return response()->json([
            'data' => ['items' => $this->compute->handle($device)],
            'errors' => [],
        ]);
    }

    public function store(ApplyDispositionRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        try {
            $result = $this->apply->handle(
                $device,
                (array) $request->validated('items'),
                $request->validated('pin'),
                $request->validated('staff_id') !== null ? (int) $request->validated('staff_id') : null,
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Invalid PIN.') {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'invalid_pin', 'message' => 'Invalid PIN.']],
                ], 401);
            }

            return response()->json([
                'data' => null,
                'errors' => [['code' => 'disposition_rejected', 'message' => $e->getMessage()]],
            ], 422);
        }

        // Stock changed — nudge the branch so other tills re-sync (the
        // SyncEventDispatcher best-effort policy: a Reverb outage never
        // fails the already-committed disposition).
        try {
            event(new DeviceSyncBroadcast(
                companyId: (int) $device->company_id,
                branchId: (int) $device->branch_id,
                eventId: 0,
                type: 'stock.disposition',
                result: $result,
            ));
        } catch (Throwable) {
            // Advisory only.
        }

        return response()->json([
            'data' => $result,
            'errors' => [],
        ]);
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
