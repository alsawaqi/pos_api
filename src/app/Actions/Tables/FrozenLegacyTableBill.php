<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Order;
use App\Support\Money;
use App\Support\Pricing\BillMoney;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** No catalogue/pricing read: retain the source and copy its frozen children. */
final class FrozenLegacyTableBill
{
    public function snapshot(Order $order): array
    {
        $items = DB::table('pos_order_items')->where('order_id', $order->id)->orderBy('id')->get();

        return [
            'order' => $order->getRawOriginal(),
            'items' => $items->map(fn ($row): array => (array) $row)->all(),
            'addons' => DB::table('pos_order_item_addons')->whereIn('order_item_id', $items->pluck('id'))
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'discounts' => DB::table('pos_order_discounts')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'comps' => DB::table('pos_order_comps')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'tables' => DB::table('pos_order_tables')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'rounds' => DB::table('pos_qr_order_rounds')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    public function totals(array $snapshot): array
    {
        $result = [];
        foreach (['subtotal', 'discount_total', 'comp_total', 'tax_total', 'grand_total'] as $key) {
            $result[$key.'_baisas'] = Money::toBaisas($snapshot['order'][$key]);
        }

        return $result;
    }

    /** Refuse unsupported accounting; never reinterpret a comp as a discount. */
    public function assertImportable(array $snapshot): void
    {
        $totals = $this->totals($snapshot);
        $items = $snapshot['items'];
        if ($snapshot['comps'] !== [] || $totals['comp_total_baisas'] !== 0
            || $items === [] || count($items) > 1000 || min($totals) < 0) {
            throw $this->unsupported();
        }
        $subtotal = 0;
        $lineDiscounts = 0;
        foreach ($items as $item) {
            if ($item['status'] === 'void' && (float) $item['qty'] === 0.0) {
                continue;
            }
            $qty = (float) $item['qty'];
            $unit = Money::toBaisas($item['unit_price_snapshot']);
            $line = Money::toBaisas($item['line_total']);
            $discount = Money::toBaisas($item['line_discount']);
            if ($item['status'] !== 'open' || $qty < 1 || $qty > 999 || floor($qty) !== $qty
                || $item['product_id'] === null || $unit < 0 || $discount < 0 || $discount > $line
                || $line !== $unit * (int) $qty) {
                throw $this->unsupported();
            }
            $attributed = array_sum(array_map(fn ($row): int => Money::toBaisas($row['amount']),
                array_filter($snapshot['discounts'], fn ($row): bool => (int) $row['order_item_id'] === (int) $item['id'])));
            if ($attributed !== $discount) {
                throw $this->unsupported();
            }
            $subtotal += $line;
            $lineDiscounts += $discount;
        }
        $discountTotal = array_sum(array_map(fn ($row): int => Money::toBaisas($row['amount']), $snapshot['discounts']));
        $ids = array_column($items, 'id');
        foreach ($snapshot['discounts'] as $row) {
            if ((int) $row['company_id'] !== (int) $snapshot['order']['company_id']
                || (int) $row['branch_id'] !== (int) $snapshot['order']['branch_id']
                || ($row['order_item_id'] !== null && ! in_array($row['order_item_id'], $ids))) {
                throw $this->unsupported();
            }
        }
        if ($subtotal !== $totals['subtotal_baisas'] || $subtotal === 0
            || $discountTotal !== $totals['discount_total_baisas'] || $lineDiscounts > $discountTotal
            || $discountTotal > $subtotal
            || BillMoney::total($subtotal - $discountTotal, $totals['tax_total_baisas'], (bool) ($snapshot['order']['prices_include_tax'] ?? false))
                !== $totals['grand_total_baisas']) {
            throw $this->unsupported();
        }
    }

    public function present(array $snapshot): array
    {
        return Arr::only($snapshot['order'], ['uuid', 'source', 'status', 'temp_reference', 'receipt_number', 'opened_at'])
            + $this->totals($snapshot) + ['items' => array_map(function (array $item) use ($snapshot): array {
                return ['id' => (int) $item['id'], 'product_id' => $item['product_id'],
                    'name' => $item['product_name_snapshot'], 'qty' => (float) $item['qty'],
                    'status' => $item['status'], 'notes' => $item['notes'],
                    'unit_price_baisas' => Money::toBaisas($item['unit_price_snapshot']),
                    'line_discount_baisas' => Money::toBaisas($item['line_discount']),
                    'line_total_baisas' => Money::toBaisas($item['line_total']),
                    'addons' => array_values(array_map(fn ($addon): array => [
                        'id' => $addon['add_on_id'], 'name' => $addon['add_on_name_snapshot'],
                        'price_delta_baisas' => Money::toBaisas($addon['price_delta_snapshot']),
                    ], array_filter($snapshot['addons'], fn ($addon): bool => (int) $addon['order_item_id'] === (int) $item['id'])))];
            }, $snapshot['items'])];
    }

    /** Original ids remain on the archived source; new ids own the bill lines. */
    public function copyTo(array $snapshot, Order $target): array
    {
        $map = [];
        $lines = [];
        foreach ($snapshot['items'] as $item) {
            $id = DB::table('pos_order_items')->insertGetId(array_replace(Arr::except($item, ['id']), ['order_id' => $target->id]));
            $map[(int) $item['id']] = (int) $id;
            $addons = [];
            foreach ($snapshot['addons'] as $addon) {
                if ((int) $addon['order_item_id'] !== (int) $item['id']) {
                    continue;
                }
                DB::table('pos_order_item_addons')->insert(array_replace(Arr::except($addon, ['id']), ['order_item_id' => $id]));
                $addons[] = ['add_on_id' => $addon['add_on_id'], 'name' => $addon['add_on_name_snapshot'],
                    'price_delta_baisas' => Money::toBaisas($addon['price_delta_snapshot'])];
            }
            if ($item['status'] === 'void') {
                continue;
            }
            $unit = Money::toBaisas($item['unit_price_snapshot']);
            $lines[] = ['line_index' => count($lines), 'product_id' => (int) $item['product_id'],
                'product_name' => $item['product_name_snapshot'], 'qty' => (int) $item['qty'],
                'notes' => $item['notes'], 'addons' => $addons, 'unit_price_baisas' => $unit,
                'base_price_baisas' => $unit - array_sum(array_column($addons, 'price_delta_baisas')),
                'line_discount_baisas' => Money::toBaisas($item['line_discount']),
                'line_total_baisas' => Money::toBaisas($item['line_total']), 'order_item_id' => $id];
        }
        foreach ($snapshot['discounts'] as $row) {
            DB::table('pos_order_discounts')->insert(array_replace(Arr::except($row, ['id']), [
                'order_id' => $target->id, 'order_item_id' => $row['order_item_id'] === null ? null : $map[$row['order_item_id']],
            ]));
        }

        return ['item_id_map' => $map, 'priced_lines' => $lines];
    }

    private function unsupported(): QrDineInException
    {
        return new QrDineInException('combine_accounting_unsupported', 409,
            'This bill has comps, fractional/custom lines or inconsistent frozen accounting. Keep both bills unchanged for separate manager review.');
    }
}
