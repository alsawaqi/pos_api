<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Actions\Tables\CancelStaffLineAction;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class CancelStaffLineAccountingTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
    }

    public function test_worked_discount_and_tax_table_to_the_baisa_with_append_only_audits_and_journal_replay(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating);
        $a = $this->seatingProduct();
        $b = $this->seatingProduct();
        [$itemA, $lineA] = $this->frozenLine($order, (int) $a->id, 0, 1000, 3, 300);
        [$itemB, $lineB] = $this->frozenLine($order, (int) $b->id, 1, 2000, 1, 0);
        $round = $this->seatingRound($seating, $order, [
            'priced_lines' => [$lineA, $lineB],
            'subtotal_baisas' => 5000, 'tax_baisas' => 223, 'total_baisas' => 4673,
        ]);
        $originalLineAudit = $this->discount($order, (int) $itemA->id, 300, 'Line rule');
        $originalOrderAudit = $this->discount($order, null, 250, 'Order rule');
        $originalAudits = OrderDiscount::query()->orderBy('id')->get()->keyBy('id')->toArray();
        app(RefreshQrOrderTotalsAction::class)->handle($order);
        $this->assertMoney($round, $order, [5000, 300, 250, 550, 4450, 223, 4673], ['5.000', '0.550', '0.223', '4.673']);
        $a->update(['base_price' => '99.999']);
        $b->update(['base_price' => '88.888']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $a->id, 1, 'step-1'), now(), now());
        $this->assertSame('cancelled', $first['outcome']);
        $this->assertSame(1, $first['cancelled_qty']);
        $this->assertSame(0, $first['unlinked_line_count']);
        $this->assertSame(3778, $first['grand_total_baisas']);
        $this->assertMoney($round, $order, [4000, 200, 202, 402, 3598, 180, 3778], ['4.000', '0.402', '0.180', '3.778']);
        $this->assertItem($itemA, ['2.000', '2.000', '0.200', OrderItem::STATUS_OPEN]);
        $this->assertSame(['-0.100', '-0.048'], $this->adjustments('step-1'));
        $this->assertSame([[
            'round_id' => (int) $round->id, 'line_index' => 0, 'qty' => 1,
            'unit_price_baisas' => 1000, 'discount_baisas' => 100,
            'subtotal_baisas' => 4000, 'tax_baisas' => 180, 'total_baisas' => 3778,
        ]], $first['rounds']);
        $expectedJournal = [
            'table_session_uuid' => $seating->uuid, 'order_uuid' => $order->uuid,
            'waste' => ['booked' => false, 'cost_baisas' => 0, 'ingredients' => []],
            'cancelled_qty' => 1, 'unlinked_line_count' => 0, 'grand_total_baisas' => 3778,
            'rounds' => $first['rounds'], 'action' => 'line_cancelled', 'client_request_id' => 'step-1',
            'product_id' => (int) $a->id, 'addon_ids' => [], 'notes_normalised' => '',
            'qty' => 1, 'prepared' => false, 'reason' => 'Cashier correction',
            'authorized_by' => 'Manager', 'staff_id' => null, 'whole_bill' => false,
        ];
        $journal = TableSessionEvent::query()->sole();
        $this->assertSame('round_resolved', $journal->event_type);
        $this->assertSame((int) $seating->id, (int) $journal->table_session_id);
        $this->assertSame($expectedJournal, $journal->payload);
        $this->assertSame($lineA + [
            'cancelled_qty' => 1, 'cancelled_discount_baisas' => 100,
            'cancellations' => [['client_request_id' => 'step-1', 'qty' => 1, 'discount_baisas' => 100, 'at' => now()->toIso8601String()]],
        ], $round->fresh()->priced_lines[0]);

        $second = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $a->id, 2, 'step-2'), now(), now());
        $this->assertSame('cancelled', $second['outcome']);
        $this->assertSame(2, $second['cancelled_qty']);
        $this->assertSame(1989, $second['grand_total_baisas']);
        $this->assertMoney($round, $order, [2000, 0, 106, 106, 1894, 95, 1989], ['2.000', '0.106', '0.095', '1.989']);
        $this->assertItem($itemA, ['0.000', '0.000', '0.000', OrderItem::STATUS_VOID]);
        $this->assertSame(['-0.200', '-0.096'], $this->adjustments('step-2'));
        $this->assertSame(3, $round->fresh()->priced_lines[0]['cancelled_qty']);
        $this->assertSame(300, $round->fresh()->priced_lines[0]['cancelled_discount_baisas']);
        $this->assertSame([
            ['client_request_id' => 'step-1', 'qty' => 1, 'discount_baisas' => 100, 'at' => now()->toIso8601String()],
            ['client_request_id' => 'step-2', 'qty' => 2, 'discount_baisas' => 200, 'at' => now()->toIso8601String()],
        ], $round->fresh()->priced_lines[0]['cancellations']);

        $third = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $b->id, 1, 'step-3'), now(), now());
        $this->assertSame('cancelled', $third['outcome']);
        $this->assertSame(1, $third['cancelled_qty']);
        $this->assertSame(0, $third['grand_total_baisas']);
        $this->assertMoney($round, $order, [0, 0, 0, 0, 0, 0, 0], ['0.000', '0.000', '0.000', '0.000']);
        $this->assertItem($itemB, ['0.000', '0.000', '0.000', OrderItem::STATUS_VOID]);
        $this->assertSame(['-0.106'], $this->adjustments('step-3'));
        $this->assertSame($originalAudits[$originalLineAudit->id], $originalLineAudit->fresh()->toArray());
        $this->assertSame($originalAudits[$originalOrderAudit->id], $originalOrderAudit->fresh()->toArray());
        $this->assertSame(0, Money::toBaisas(OrderDiscount::query()->where('order_id', $order->id)->sum('amount')));
        foreach (OrderDiscount::query()->where('amount', '<', 0)->get() as $adjustment) {
            if ($adjustment->order_item_id !== null) {
                $this->assertSame($originalLineAudit->only(['discount_id', 'offer_id', 'order_item_id', 'name_snapshot', 'amount_type_snapshot']),
                    $adjustment->only(['discount_id', 'offer_id', 'order_item_id', 'name_snapshot', 'amount_type_snapshot']));
            } else {
                $this->assertSame([null, null, 'Line cancellation adjustment', 'cancel_line'], [
                    $adjustment->discount_id, $adjustment->offer_id, $adjustment->name_snapshot, $adjustment->amount_type_snapshot,
                ]);
            }
        }
        $beforeNoOp = $this->moneySnapshot($order, $round);
        $fourth = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $a->id, 1, 'step-4'), now(), now());
        $this->assertSame('nothing_to_cancel', $fourth['outcome']);
        $this->assertSame(0, $fourth['cancelled_qty']);
        $this->assertSame($beforeNoOp, $this->moneySnapshot($order, $round));
        $replay = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $a->id, 1, 'step-1'), now(), now());
        $this->assertSame('replayed', $replay['outcome']);
        $this->assertSame(1, $replay['cancelled_qty']);
        $this->assertSame(3778, $replay['grand_total_baisas']);
        $this->assertSame($first['rounds'], $replay['rounds']);
        $this->assertSame($beforeNoOp, $this->moneySnapshot($order, $round));
        $this->assertNoCatalogueReads();
        fwrite(STDOUT, "\nT6_CANCEL_STEP_1=".json_encode($first, JSON_THROW_ON_ERROR)."\nT6_CANCEL_JOURNAL=".json_encode($journal->payload, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_historical_prices_cancel_newest_round_first_and_short_fulfil_without_catalogue_reads(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating);
        $product = $this->seatingProduct();
        [$oldItem, $oldLine] = $this->frozenLine($order, (int) $product->id, 0, 1000, 1, 0);
        [$newItem, $newLine] = $this->frozenLine($order, (int) $product->id, 0, 1200, 2, 0);
        $oldRound = $this->seatingRound($seating, $order, [
            'priced_lines' => [$oldLine], 'subtotal_baisas' => 1000, 'tax_baisas' => 50, 'total_baisas' => 1050,
        ]);
        $newRound = $this->seatingRound($seating, $order, [
            'round_no' => 2, 'priced_lines' => [$newLine], 'subtotal_baisas' => 2400, 'tax_baisas' => 120, 'total_baisas' => 2520,
        ]);
        app(RefreshQrOrderTotalsAction::class)->handle($order);
        $oldSnapshot = $oldRound->fresh()->getRawOriginal();
        $product->update(['base_price' => '1.500']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $product->id, 2, 'historical-1'), now(), now());
        $this->assertSame('cancelled', $first['outcome']);
        $this->assertSame(2, $first['cancelled_qty']);
        $this->assertSame(1050, $first['grand_total_baisas']);
        $this->assertSame((int) $newRound->id, $first['rounds'][0]['round_id']);
        $this->assertSame(1200, $first['rounds'][0]['unit_price_baisas']);
        $this->assertSame($oldSnapshot, $oldRound->fresh()->getRawOriginal());
        $this->assertMoney($newRound, $order, [0, 0, 0, 0, 0, 0, 0], ['1.000', '0.000', '0.050', '1.050']);
        $this->assertItem($newItem, ['0.000', '0.000', '0.000', OrderItem::STATUS_VOID]);
        $this->assertItem($oldItem, ['1.000', '1.000', '0.000', OrderItem::STATUS_OPEN]);
        $second = app(CancelStaffLineAction::class)->handle($device, $this->payload($seating, (int) $product->id, 2, 'historical-2'), now(), now());
        $this->assertSame('cancelled', $second['outcome']);
        $this->assertSame(1, $second['cancelled_qty']);
        $this->assertSame(0, $second['grand_total_baisas']);
        $this->assertSame((int) $oldRound->id, $second['rounds'][0]['round_id']);
        $this->assertSame(1000, $second['rounds'][0]['unit_price_baisas']);
        $this->assertMoney($oldRound, $order, [0, 0, 0, 0, 0, 0, 0], ['0.000', '0.000', '0.000', '0.000']);
        $this->assertItem($oldItem, ['0.000', '0.000', '0.000', OrderItem::STATUS_VOID]);
        $this->assertDatabaseCount('pos_order_discounts', 0);
        $this->assertDatabaseCount('pos_table_session_events', 2);
        $this->assertNoCatalogueReads();
    }

    private function payload(TableSession $seating, int $productId, int $qty, string $request): array
    {
        return [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => $request, 'product_id' => $productId,
            'addon_ids' => [], 'notes' => null, 'qty' => $qty, 'prepared' => false,
            'reason' => 'Cashier correction', 'authorized_by' => 'Manager', 'cancelled_at' => now()->toIso8601String(),
        ];
    }

    private function frozenLine(Order $order, int $productId, int $index, int $unit, int $qty, int $discount): array
    {
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $productId, 'product_name_snapshot' => 'Frozen product',
            'qty' => $qty, 'unit_price_snapshot' => Money::toOmr($unit), 'line_total' => Money::toOmr($unit * $qty),
            'line_discount' => Money::toOmr($discount), 'status' => OrderItem::STATUS_OPEN,
        ]);

        return [$item, [
            'line_index' => $index, 'product_id' => $productId, 'qty' => $qty,
            'unit_price_baisas' => $unit, 'line_total_baisas' => $unit * $qty, 'line_discount_baisas' => $discount,
            'addons' => [], 'notes' => null, 'order_item_id' => (int) $item->id,
        ]];
    }

    private function discount(Order $order, ?int $itemId, int $amount, string $name): OrderDiscount
    {
        return OrderDiscount::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'order_item_id' => $itemId, 'discount_id' => null, 'offer_id' => null,
            'name_snapshot' => $name, 'amount_type_snapshot' => 'percent',
            'amount' => Money::toOmr($amount), 'applied_at' => now(),
        ]);
    }

    private function adjustments(string $request): array
    {
        return OrderDiscount::query()->where('reason', 'cancel:'.$request)->orderBy('id')->get()->pluck('amount')->all();
    }

    private function assertMoney(QrOrderRound $round, Order $order, array $expectedRound, array $expectedHeader): void
    {
        $round->refresh();
        $order->refresh();
        $s = (int) $round->subtotal_baisas;
        $t = (int) $round->tax_baisas;
        $total = (int) $round->total_baisas;
        $ld = array_sum(array_map(static fn (array $line): int => $line['line_discount_baisas'] - ($line['cancelled_discount_baisas'] ?? 0), $round->priced_lines));
        $d = $s + $t - $total;
        $this->assertSame($expectedRound, [$s, $ld, $d - $ld, $d, $s - $d, $t, $total]);
        $this->assertSame($expectedHeader, [$order->subtotal, $order->discount_total, $order->tax_total, $order->grand_total]);
    }

    private function assertItem(OrderItem $item, array $expected): void
    {
        $item->refresh();
        $this->assertSame($expected, [$item->qty, $item->line_total, $item->line_discount, $item->status]);
    }

    private function moneySnapshot(Order $order, QrOrderRound $round): array
    {
        return [$order->fresh()->getRawOriginal(), $round->fresh()->getRawOriginal(),
            OrderItem::query()->orderBy('id')->get()->toArray(), OrderDiscount::query()->orderBy('id')->get()->toArray(),
            TableSessionEvent::query()->orderBy('id')->get()->toArray()];
    }

    private function assertNoCatalogueReads(): void
    {
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\bpos_(products|addons|discounts|offers|taxes|branch_products|product_recipes)\b/i', $query['query']);
        }
        DB::disableQueryLog();
    }
}
