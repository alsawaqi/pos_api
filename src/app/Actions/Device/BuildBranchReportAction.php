<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P-F6 — assembles the device's branch Reports dashboard payload
 * (GET /api/v1/device/reports/branch).
 *
 * Scope: the DEVICE'S company + branch only; PAID orders with opened_at
 * inside [from 00:00:00, to 23:59:59] server tz (voided / refunded
 * excluded — their reversals also cancel out of the consumption ledger).
 * Money is integer BAISAS throughout, matching the /device/config money
 * contract.
 *
 * Built from a handful of GROUPED queries (one per report section) — never
 * per-row loops — so a 30-day window over thousands of orders stays a
 * constant number of statements. Date/hour bucketing is driver-aware
 * (sqlite strftime in tests, Postgres EXTRACT in prod), mirroring
 * pos_merchant's SalesReportAction.
 */
class BuildBranchReportAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Device $device, Carbon $from, Carbon $to): array
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        // The PAID orders of THIS branch in the window — the base every
        // section derives from. Cloned per use; child tables (payments,
        // items, discounts, comps, loyalty) scope through an `order_id IN
        // (subquery)` so each section stays a single grouped statement.
        $paid = DB::table('pos_orders')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.branch_id', $branchId)
            ->where('pos_orders.status', Order::STATUS_PAID)
            ->whereBetween('pos_orders.opened_at', [$start, $end]);

        $orderIds = fn (): Builder => (clone $paid)->select('pos_orders.id');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $this->summary($paid, $orderIds),
            'by_day' => $this->byDay($paid),
            'by_hour' => $this->byHour($paid),
            'by_method' => $this->byMethod($orderIds),
            'by_order_type' => $this->byOrderType($paid),
            'top_products' => $this->topProducts($orderIds),
            'top_customers' => $this->topCustomers($paid),
            'discounts' => $this->discounts($orderIds),
            'stock_consumption' => $this->stockConsumption($branchId, $start, $end),
        ];
    }

    /**
     * @param  callable(): Builder  $orderIds
     * @return array<string, int>
     */
    private function summary(Builder $paid, callable $orderIds): array
    {
        $row = (clone $paid)->selectRaw(
            'COUNT(*) AS orders_count,'
            .' COALESCE(SUM(grand_total), 0) AS gross,'
            .' COALESCE(SUM(discount_total), 0) AS discounts,'
            .' COALESCE(SUM(tax_total), 0) AS tax,'
            .' COUNT(DISTINCT customer_id) AS distinct_customers'
        )->first();

        $orders = (int) ($row->orders_count ?? 0);
        $gross = $this->baisas($row->gross ?? 0);

        // Comps split by the P-F5 is_gift flag: comp_baisas = reasoned
        // (manager-approved) comps only; gift_baisas = the gifted lines.
        $compBaisas = 0;
        $giftBaisas = 0;
        $compRows = DB::table('pos_order_comps')
            ->whereIn('order_id', $orderIds())
            ->selectRaw('is_gift, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('is_gift')
            ->get();
        foreach ($compRows as $r) {
            if ((bool) $r->is_gift) {
                $giftBaisas += $this->baisas($r->total);
            } else {
                $compBaisas += $this->baisas($r->total);
            }
        }

        // Loyalty figures from the append-only ledger, scoped to these
        // orders. Earn rows carry positive deltas; redeem rows negative
        // (WriteLoyaltyTransactionAction) — emit redeemed as positive.
        $loyalty = DB::table('pos_loyalty_transactions')
            ->whereIn('order_id', $orderIds())
            ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_REDEEM])
            ->selectRaw('type, COALESCE(SUM(points_delta), 0) AS points, COALESCE(SUM(stamps_delta), 0) AS stamps')
            ->groupBy('type')
            ->get()
            ->keyBy('type');
        $earn = $loyalty->get(LoyaltyTransaction::TYPE_EARN);
        $redeem = $loyalty->get(LoyaltyTransaction::TYPE_REDEEM);

        return [
            'orders' => $orders,
            'gross_baisas' => $gross,
            'discount_baisas' => $this->baisas($row->discounts ?? 0),
            'comp_baisas' => $compBaisas,
            'gift_baisas' => $giftBaisas,
            'tax_baisas' => $this->baisas($row->tax ?? 0),
            'avg_order_baisas' => $orders > 0 ? (int) round($gross / $orders) : 0,
            'distinct_customers' => (int) ($row->distinct_customers ?? 0),
            'loyalty_points_earned' => $earn !== null ? (int) $earn->points : 0,
            'loyalty_points_redeemed' => $redeem !== null ? abs((int) $redeem->points) : 0,
            'loyalty_stamps_earned' => $earn !== null ? (int) $earn->stamps : 0,
            'loyalty_stamps_redeemed' => $redeem !== null ? abs((int) $redeem->stamps) : 0,
        ];
    }

    /**
     * Per-day totals, ascending. Sparse — days without sales are omitted
     * (the device fills the gaps).
     *
     * @return list<array{date: string, total_baisas: int, orders: int}>
     */
    private function byDay(Builder $paid): array
    {
        return (clone $paid)
            ->selectRaw('DATE(opened_at) AS sales_date, COALESCE(SUM(grand_total), 0) AS total, COUNT(*) AS cnt')
            ->groupByRaw('DATE(opened_at)')
            ->orderByRaw('DATE(opened_at)')
            ->get()
            ->map(fn ($r): array => [
                'date' => substr((string) $r->sales_date, 0, 10),
                'total_baisas' => $this->baisas($r->total),
                'orders' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Per-hour-of-day totals (0-23, sparse, ascending). Driver-aware hour
     * extraction — the SalesReportAction precedent.
     *
     * @return list<array{hour: int, total_baisas: int, orders: int}>
     */
    private function byHour(Builder $paid): array
    {
        $hourExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)::int';

        return (clone $paid)
            ->selectRaw("$hourExpr AS hour, COALESCE(SUM(grand_total), 0) AS total, COUNT(*) AS cnt")
            ->groupByRaw('hour')
            ->orderByRaw('hour')
            ->get()
            ->map(fn ($r): array => [
                'hour' => (int) $r->hour,
                'total_baisas' => $this->baisas($r->total),
                'orders' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Tender mix from pos_payments of the window's paid orders — raw method
     * strings (cash / card / split_part / loyalty / gift / bank_pos), value
     * descending.
     *
     * @param  callable(): Builder  $orderIds
     * @return list<array{method: string, total_baisas: int, count: int}>
     */
    private function byMethod(callable $orderIds): array
    {
        return DB::table('pos_payments')
            ->whereIn('order_id', $orderIds())
            ->selectRaw('method, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r): array => [
                'method' => (string) $r->method,
                'total_baisas' => $this->baisas($r->total),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * @return list<array{order_type: string, total_baisas: int, count: int}>
     */
    private function byOrderType(Builder $paid): array
    {
        return (clone $paid)
            ->selectRaw('order_type, COALESCE(SUM(grand_total), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('order_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r): array => [
                'order_type' => (string) $r->order_type,
                'total_baisas' => $this->baisas($r->total),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Top 10 products by line value, grouped by the order items' name
     * snapshot (rename-proof). qty may be decimal (weighed goods) — emitted
     * as a number.
     *
     * @param  callable(): Builder  $orderIds
     * @return list<array{name: string, qty: float, total_baisas: int}>
     */
    private function topProducts(callable $orderIds): array
    {
        return DB::table('pos_order_items')
            ->whereIn('order_id', $orderIds())
            ->selectRaw('product_name_snapshot AS name, COALESCE(SUM(qty), 0) AS qty, COALESCE(SUM(line_total), 0) AS total')
            ->groupBy('product_name_snapshot')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r): array => [
                'name' => (string) $r->name,
                'qty' => (float) $r->qty,
                'total_baisas' => $this->baisas($r->total),
            ])->all();
    }

    /**
     * Top 10 customers by value among orders that carried a customer_id;
     * the name resolves from pos_customers (DB join, so even a since-
     * soft-deleted customer still labels their past sales).
     *
     * @return list<array{name: string, orders: int, total_baisas: int}>
     */
    private function topCustomers(Builder $paid): array
    {
        return (clone $paid)
            ->join('pos_customers', 'pos_customers.id', '=', 'pos_orders.customer_id')
            ->whereNotNull('pos_orders.customer_id')
            ->selectRaw('pos_customers.id AS customer_id, pos_customers.name AS name, COUNT(*) AS cnt, COALESCE(SUM(pos_orders.grand_total), 0) AS total')
            ->groupBy('pos_customers.id', 'pos_customers.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r): array => [
                'name' => (string) $r->name,
                'orders' => (int) $r->cnt,
                'total_baisas' => $this->baisas($r->total),
            ])->all();
    }

    /**
     * Discount applications grouped by name snapshot — rule-driven and
     * manual discounts sharing a name aggregate together, value descending.
     *
     * @param  callable(): Builder  $orderIds
     * @return list<array{name: string, amount_baisas: int, count: int}>
     */
    private function discounts(callable $orderIds): array
    {
        return DB::table('pos_order_discounts')
            ->whereIn('order_id', $orderIds())
            ->selectRaw('name_snapshot AS name, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('name_snapshot')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r): array => [
                'name' => (string) $r->name,
                'amount_baisas' => $this->baisas($r->total),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Top 15 ingredients consumed at this branch in the window, from the
     * append-only pos_stock_movements ledger. ConsumeInventoryAction writes
     * sale_consumption (recipe lines) + addon_consumption (add-on
     * ingredients) rows with NEGATIVE quantities on pay and positive
     * reversals on void — so net-summing both types per ingredient yields
     * the true consumption with voided sales cancelled out. Emitted as
     * POSITIVE consumed qty; name/unit from pos_ingredients (DB join, so a
     * soft-deleted ingredient still labels its history).
     *
     * @return list<array{name: string, qty: float, unit: string}>
     */
    private function stockConsumption(int $branchId, Carbon $start, Carbon $end): array
    {
        return DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_stock_movements.branch_id', $branchId)
            ->whereIn('pos_stock_movements.movement_type', [
                StockMovement::TYPE_SALE_CONSUMPTION,
                StockMovement::TYPE_ADDON_CONSUMPTION,
            ])
            ->whereBetween('pos_stock_movements.occurred_at', [$start, $end])
            ->selectRaw(
                'pos_stock_movements.ingredient_id AS ingredient_id,'
                .' pos_ingredients.name AS name,'
                .' pos_ingredients.unit AS unit,'
                .' COALESCE(SUM(pos_stock_movements.quantity), 0) AS net'
            )
            ->groupBy('pos_stock_movements.ingredient_id', 'pos_ingredients.name', 'pos_ingredients.unit')
            ->havingRaw('COALESCE(SUM(pos_stock_movements.quantity), 0) < 0')
            ->orderByRaw('SUM(pos_stock_movements.quantity) ASC')
            ->limit(15)
            ->get()
            ->map(fn ($r): array => [
                'name' => (string) $r->name,
                'qty' => round(-(float) $r->net, 3),
                'unit' => (string) $r->unit,
            ])->all();
    }

    /**
     * Convert a decimal(…,3) OMR string/number (SQL SUM output) to integer
     * baisas — the same rounding rule as {@see \App\Support\Money}.
     */
    private function baisas(int|float|string|null $value): int
    {
        return $value === null ? 0 : (int) round(((float) $value) * 1000);
    }
}
