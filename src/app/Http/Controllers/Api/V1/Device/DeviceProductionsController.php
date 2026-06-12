<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\Production\CancelProductionAction;
use App\Actions\Device\Production\FinishProductionAction;
use App\Actions\Device\Production\StartProductionAction;
use App\Events\DeviceSyncBroadcast;
use App\Http\Requests\Api\V1\Device\CancelProductionRequest;
use App\Http\Requests\Api\V1\Device\FinishProductionRequest;
use App\Http\Requests\Api\V1\Device\StartProductionRequest;
use App\Models\Device;
use App\Models\Production;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * P-G1 — kitchen production lifecycle (ONLINE-ONLY, unlike the order
 * pipeline: the server validates fresh ingredient balances at each phase,
 * so these are plain authenticated endpoints, not sync events).
 *
 *   POST /device/productions                 start: recipe x qty LOCKED,
 *                                            extras declared, ingredients
 *                                            deducted immediately
 *   POST /device/productions/{uuid}/finish   pieces land in shelf stock,
 *                                            duration recorded
 *   POST /device/productions/{uuid}/cancel   manager-PIN gated, returns
 *                                            the ingredients
 *
 * Each success fires DeviceSyncBroadcast on the branch channel
 * (production.start / production.finish / production.cancel) so every
 * other till runs its config-delta refresh — finish is what un-greys the
 * product tile (shelf stock 0 -> N) without anyone touching the screen.
 */
class DeviceProductionsController
{
    public function __construct(
        private readonly StartProductionAction $start,
        private readonly FinishProductionAction $finish,
        private readonly CancelProductionAction $cancel,
    ) {}

    public function store(StartProductionRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        try {
            $production = $this->start->handle(
                $device,
                (int) $request->validated('product_id'),
                (int) $request->validated('quantity'),
                $request->validated('staff_id') !== null ? (int) $request->validated('staff_id') : null,
                (array) ($request->validated('extras') ?? []),
            );
        } catch (RuntimeException $e) {
            return $this->domainError($e);
        }

        $this->broadcast($device, $production, 'production.start');

        return response()->json([
            'data' => ['production' => DeviceKitchenController::productionPayload($production)],
            'errors' => [],
        ], 201);
    }

    public function finish(FinishProductionRequest $request, string $uuid): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        try {
            $production = $this->finish->handle(
                $device,
                $uuid,
                $request->validated('staff_id') !== null ? (int) $request->validated('staff_id') : null,
            );
        } catch (RuntimeException $e) {
            return $this->domainError($e);
        }

        $this->broadcast($device, $production, 'production.finish');

        return response()->json([
            'data' => ['production' => DeviceKitchenController::productionPayload($production)],
            'errors' => [],
        ]);
    }

    public function cancel(CancelProductionRequest $request, string $uuid): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        try {
            $production = $this->cancel->handle(
                $device,
                $uuid,
                (string) $request->validated('pin'),
                $request->validated('staff_id') !== null ? (int) $request->validated('staff_id') : null,
            );
        } catch (RuntimeException $e) {
            // The PIN failure maps to the same generic 401 the verify
            // endpoint uses; everything else is a domain 422.
            if ($e->getMessage() === 'Invalid PIN.') {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'invalid_pin', 'message' => 'Invalid PIN.']],
                ], 401);
            }

            return $this->domainError($e);
        }

        $this->broadcast($device, $production, 'production.cancel');

        return response()->json([
            'data' => ['production' => DeviceKitchenController::productionPayload($production)],
            'errors' => [],
        ]);
    }

    /**
     * Best-effort live push AFTER the domain write committed — a Reverb
     * outage never fails the production (the SyncEventDispatcher policy).
     */
    private function broadcast(Device $device, Production $production, string $type): void
    {
        try {
            event(new DeviceSyncBroadcast(
                companyId: (int) $device->company_id,
                branchId: $device->branch_id !== null ? (int) $device->branch_id : null,
                eventId: (int) $production->id,
                type: $type,
                result: [
                    'production_uuid' => $production->uuid,
                    'product_id' => (int) $production->product_id,
                    'status' => $production->status,
                ],
            ));
        } catch (Throwable) {
            // Live push is advisory; the config delta heals on next sync.
        }
    }

    private function domainError(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'production_rejected', 'message' => $e->getMessage()]],
        ], 422);
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
