<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\MoveQuickQrOrderToCounterAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\ReleaseQrChargeAction;
use App\Actions\Qr\ReopenDineInQrPaymentAction;
use App\Models\Device;
use App\Models\Order;
use App\Support\QrApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Back before tender is an atomic release, never a station handoff. */
final class DeviceQrCancelSettlementController
{
    public function __invoke(Request $request, PresentQrPendingOrderAction $present, ReleaseQrChargeAction $release, MoveQuickQrOrderToCounterAction $move, ReopenDineInQrPaymentAction $reopen): JsonResponse
    {
        $input = $request->validate([
            'order_uuid' => ['required', 'uuid'],
            'charge_claimed_at' => ['required', 'date'],
            'charge_deadline_at' => ['required', 'date'],
        ]);
        /** @var Device $device */
        $device = $request->user();
        try {
            $present->assertAttended($device);
            $result = DB::transaction(function () use ($device, $input, $present, $release, $move, $reopen): array {
                $order = Order::query()->where('uuid', $input['order_uuid'])
                    ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                    ->where('source', Order::SOURCE_QR_WEB)
                    ->where(function ($query): void {
                        $query->where(fn ($quick) => $quick->where('order_type', 'quick')->whereNull('table_id'))
                            ->orWhere(fn ($dineIn) => $dineIn->where('order_type', 'dine_in')->whereNotNull('table_id'));
                    })->lockForUpdate()->first();
                if ($order === null) {
                    throw new QrChargeException('order_not_found', 404, 'The QR order was not found.');
                }
                // Lost ACK replay is read-only. It cannot clear a later claim.
                $returnStatus = $order->order_type === 'dine_in' ? Order::STATUS_OPEN : Order::STATUS_HELD;
                if ($order->status === $returnStatus && $present->hasNoChargeProvenance($order)) {
                    return ['order_uuid' => $order->uuid, 'status' => $returnStatus];
                }
                if ((int) $order->charge_device_id !== (int) $device->id
                    || ! $order->charge_claimed_at?->equalTo(Carbon::parse($input['charge_claimed_at']))
                    || ! $order->charge_deadline_at?->equalTo(Carbon::parse($input['charge_deadline_at']))
                    || $order->charge_outcome !== null || $order->payments()->exists()) {
                    throw new QrChargeException('claim_changed', 409, 'The reservation changed; retain its payment evidence.');
                }
                $release->handle($device, ['order_uuid' => $order->uuid, 'outcome' => Order::CHARGE_OUTCOME_CANCELLED]);
                if ($order->order_type === 'dine_in') {
                    $reopen->handle($device, $order->uuid);
                } else {
                    $move->handle($device, $order->uuid);
                }

                return ['order_uuid' => $order->uuid, 'status' => $returnStatus];
            }, 5);

            return QrApiResponse::success($result);
        } catch (QrChargeException|QrDineInException $e) {
            return QrApiResponse::failure($e->codeName, $e->getMessage(), $e->httpStatus);
        }
    }
}
