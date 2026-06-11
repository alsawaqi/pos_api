<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\AllocateOrderNumberAction;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/device/orders/next-number — P-F8 order-number allocation.
 *
 * The device calls this at PAYMENT time; the server atomically claims the
 * next number for the company per the merchant's `order_numbering` setting
 * (scope branch/company + optional daily reset — see
 * {@see AllocateOrderNumberAction}) and formats it (prefix + zero-pad).
 * The device prints `formatted` on the receipt and echoes it back as
 * `receipt_number` on the order.create event. Offline devices skip this
 * call and fall back to their local counter — the order wire contract
 * tolerates an order without a receipt_number.
 *
 * Success: 200 {data: {number, formatted}, meta: {scope, seq_date}, errors: []}.
 * Numbering disabled: 409 {data: null, errors: [{code: numbering_disabled}]}
 * — the device normally guards via config; this is the backstop.
 * Unassigned device: 409 {errors: [{code: device_unassigned}]} like every
 * sibling /device route.
 */
class DeviceOrderNumberController
{
    public function __construct(
        private readonly AllocateOrderNumberAction $allocate,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if (! $device->isAssigned()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
            ], 409);
        }

        $allocation = $this->allocate->handle($device);

        if ($allocation === null) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'numbering_disabled', 'message' => 'Order numbering is not enabled for this company.']],
            ], 409);
        }

        return response()->json([
            'data' => [
                'number' => $allocation['number'],
                'formatted' => $allocation['formatted'],
            ],
            'meta' => [
                'scope' => $allocation['scope'],
                // The day this number belongs to (daily reset on), else null.
                'seq_date' => $allocation['seq_date'],
            ],
            'errors' => [],
        ]);
    }
}
