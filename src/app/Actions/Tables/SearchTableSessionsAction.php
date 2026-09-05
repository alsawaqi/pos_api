<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Same operational projection as the board, with no historic/alias results. */
final class SearchTableSessionsAction
{
    public function __construct(private readonly ListTableBoardAction $board) {}

    /** @return list<array<string, mixed>> */
    public function handle(Device $device, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 32) {
            throw new QrDineInException('validation_failed', 422, 'The request was invalid.');
        }
        $rows = $this->board->handle($device);
        $phoneOrders = [];
        if (ctype_digit($query) && strlen($query) >= 4) {
            $phoneOrders = Order::query()
                ->where('pos_orders.company_id', (int) $device->company_id)
                ->where('pos_orders.branch_id', (int) $device->branch_id)
                ->where('pos_orders.order_type', 'dine_in')
                ->whereIn('pos_orders.status', ListTableBoardAction::UNPAID_STATUSES)
                ->whereIn('pos_orders.customer_id', DB::table('pos_customers')->select('id')
                    ->where('company_id', (int) $device->company_id)->whereNull('deleted_at')
                    ->where('phone', 'like', '%'.$query))
                ->pluck('pos_orders.uuid')->all();
        }
        $prefix = mb_strtolower($query);

        return array_values(array_slice(array_filter($rows, static function (array $row) use ($prefix, $phoneOrders): bool {
            $unpaid = in_array($row['bill']['status'] ?? null, ListTableBoardAction::UNPAID_STATUSES, true);
            if ($row['seating'] === null && ! $unpaid) {
                return false;
            }

            return str_starts_with(mb_strtolower($row['table_label']), $prefix)
                || str_starts_with(mb_strtolower($row['seating']['temp_reference'] ?? ''), $prefix)
                || str_starts_with(mb_strtolower($row['bill']['temp_reference'] ?? ''), $prefix)
                || in_array($row['bill']['order_uuid'] ?? null, $phoneOrders, true);
        }), 0, 20));
    }
}
