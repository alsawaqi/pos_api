<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;

final class ListQrPendingOrdersAction
{
    public function __construct(private readonly PresentQrPendingOrderAction $present) {}

    /** @return list<array<string, mixed>> */
    public function handle(Device $device): array
    {
        $this->present->assertAttended($device);
        $at = now();
        $orders = Order::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->where('order_type', 'quick')
            ->where('source', Order::SOURCE_QR_WEB)
            ->whereIn('status', [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT])
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.addons', 'comps' => fn ($q) => $q->orderBy('id')])
            ->orderBy('opened_at')
            ->orderBy('id')
            ->limit(200)
            ->get();
        $sessions = QrSession::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->whereIn('id', $orders->pluck('qr_session_id')->filter())
            ->get()->keyBy('id');
        $phones = Customer::withTrashed()
            ->where('company_id', $device->company_id)
            ->whereIn('id', $orders->pluck('customer_id')->filter())
            ->pluck('phone', 'id');

        return $orders->map(fn (Order $order): array => $this->present->handle(
            $order,
            $sessions->get($order->qr_session_id),
            $phones->get($order->customer_id),
            $at,
        ))->all();
    }
}
