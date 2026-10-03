<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class ListStationQrAwaitingOrdersAction
{
    /**
     * @return list<array{
     *     session_uuid: string,
     *     order_uuid: string,
     *     receipt_number: string|null,
     *     temp_reference: string|null,
     *     status: string,
     *     amount_baisas: int,
     *     item_count: int,
     *     opened_at: string|null
     * }>
     */
    public function handle(Device $device): array
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        $sessions = QrSession::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where(function (Builder $scope) use ($device): void {
                $scope
                    ->whereNotNull('table_id')
                    ->orWhere('device_id', (int) $device->getKey());
            })
            ->where('status', QrSession::STATUS_ORDERED)
            ->whereHas('orders', fn (Builder $orders): Builder => $this->awaitingOrders(
                $orders,
                $companyId,
                $branchId,
            ))
            ->with([
                'table:id,label',
                'orders' => fn (Relation $orders): Relation => $this->awaitingOrders(
                    $orders,
                    $companyId,
                    $branchId,
                )->withCount(['items' => fn (Builder $items): Builder => $items->where('qty', '>', 0)->whereNull('parent_order_item_id')])->oldest('id'),
            ])
            ->oldest('id')
            ->get();

        $rows = $sessions
            ->flatMap(fn (QrSession $session) => $session->orders->filter(fn (Order $order): bool => $order->transferred_to_device_id === null || (int) $order->transferred_to_device_id === (int) $device->id)->map(
                static function (Order $order) use ($session): array {
                    $row = [
                        'session_uuid' => (string) $session->uuid,
                        'order_uuid' => (string) $order->uuid,
                        'receipt_number' => $order->receipt_number,
                        'temp_reference' => $order->temp_reference,
                        'status' => (string) $order->status,
                        'amount_baisas' => Money::toBaisas($order->grand_total),
                        'item_count' => (int) $order->items_count,
                        'opened_at' => $order->opened_at?->toIso8601String(),
                    ];
                    if ($session->isDineIn()) {
                        $row['table_label'] = $session->table?->label;
                    }

                    return $row;
                },
            ))
            ->values()
            ->all();
        $direct = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('source', Order::SOURCE_QR_WEB)->where('order_type', 'quick')
            ->where('transferred_to_device_id', $device->id)->withoutLiveClaim()->withCount(['items' => fn (Builder $items): Builder => $items->where('qty', '>', 0)->whereNull('parent_order_item_id')])->get();
        foreach ($direct as $order) {
            if (in_array($order->uuid, array_column($rows, 'order_uuid'), true)) {
                continue;
            }
            $rows[] = ['session_uuid' => 'transfer-'.$order->uuid, 'order_uuid' => $order->uuid,
                'receipt_number' => $order->receipt_number, 'temp_reference' => $order->temp_reference,
                'status' => $order->status, 'amount_baisas' => Money::toBaisas($order->grand_total),
                'item_count' => $order->items_count, 'opened_at' => $order->opened_at?->toIso8601String()];
        }

        return $rows;
    }

    private function awaitingOrders(
        Builder|Relation $orders,
        int $companyId,
        int $branchId,
    ): Builder|Relation {
        return $orders
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->withoutLiveClaim();
    }
}
