<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchPackaging;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP3SyncEvents;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH packaging add-on, Part A (pos_api) — stock by order type and
 * per-order packaging (LAUNCH-PACKAGING_WORK_ORDER.md §1–§3).
 *
 * Latte 1.500 (made to order): milk 200 ml, beans 18 g; physical items
 * paper cup ×1 and lid ×1, both ticked Quick / To go / Delivery (14), no
 * mug line. "Size" group: Large = remove paper cup ×1 (14), add large cup ×1
 * (14), add milk 100 ml (all). Per-order packaging: dine in napkin ×1
 * (ingredient, pieces); to go paper bag ×1; delivery delivery bag ×1 +
 * napkin ×3; quick nothing. Shelves hold 100 of each item, ingredients
 * 10 000 base units. Masks: 1 dine in, 2 quick, 4 to go, 8 delivery.
 */
final class StockByOrderTypeTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP3SyncEvents;
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private const MILK = 41;

    private const BEANS = 42;

    private const SUGAR = 43;

    private const NAPKIN = 44;

    private const TOKEN = 'mdev_pk_stock';

    private int $latte;

    private int $cup;

    private int $lid;

    private int $largeCup;

    private int $paperBag;

    private int $deliveryBag;

    private int $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();

        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(self::MILK, 'Milk', 'ml', '0.000400'),
            $this->ingredientRow(self::BEANS, 'Beans', 'g', '0.020000'),
            $this->ingredientRow(self::SUGAR, 'Sugar', 'g', '0.001000'),
            $this->ingredientRow(self::NAPKIN, 'Napkin', 'piece', '0.005000'),
        ]);
        foreach ([self::MILK, self::BEANS, self::SUGAR, self::NAPKIN] as $id) {
            DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => $id, 'quantity' => '10000',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->latte = $this->p4Product('Latte', '1.500', ['stock_mode' => 'ingredient']);
        $this->cup = $this->item('Paper cup');
        $this->lid = $this->item('Lid');
        $this->largeCup = $this->item('Large cup');
        $this->paperBag = $this->item('Paper bag');
        $this->deliveryBag = $this->item('Delivery bag');
        $this->productRecipe($this->latte, [[self::MILK, '200', 'ml'], [self::BEANS, '18', 'g']]);
        $this->itemLine($this->latte, $this->cup, '1', 14);
        $this->itemLine($this->latte, $this->lid, '1', 14);

        $this->large = $this->p4Addons($this->latte, ['Large' => '0.000'], ['name' => 'Size'])['Large'];
        $this->consumption($this->large, ['component_product_id' => $this->cup, 'direction' => 'remove', 'quantity' => '1', 'order_types' => 14]);
        $this->consumption($this->large, ['component_product_id' => $this->largeCup, 'direction' => 'add', 'quantity' => '1', 'order_types' => 14]);
        $this->consumption($this->large, ['ingredient_id' => self::MILK, 'direction' => 'add', 'quantity' => '100', 'unit' => 'ml']);

        $this->packaging('dine_in', ['ingredient_id' => self::NAPKIN, 'quantity' => '1', 'unit' => 'piece']);
        $this->packaging('to_go', ['product_id' => $this->paperBag, 'quantity' => '1']);
        $this->packaging('delivery', ['product_id' => $this->deliveryBag, 'quantity' => '1']);
        $this->packaging('delivery', ['ingredient_id' => self::NAPKIN, 'quantity' => '3', 'unit' => 'piece', 'sort_order' => 1]);

        $this->p4Device(self::TOKEN);
    }

    // ------------------------------------------------------------ fixtures

    private function item(string $name): int
    {
        $id = $this->p4Product($name, '0.000', ['stock_mode' => 'unit', 'is_internal' => true]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $id, 'is_available' => true,
            'stock_qty' => '100.000', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function itemLine(int $productId, int $itemId, string $qty, int $mask = 15): void
    {
        DB::table('pos_product_components')->insert(['product_id' => $productId, 'component_product_id' => $itemId,
            'quantity' => $qty, 'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $row */
    private function consumption(int $addOnId, array $row): void
    {
        DB::table('pos_addon_consumptions')->insert($row + ['add_on_id' => $addOnId, 'ingredient_id' => null,
            'component_product_id' => null, 'unit' => null, 'order_types' => 15, 'display_order' => 0,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $row */
    private function packaging(string $type, array $row, int $companyId = 100): int
    {
        return (int) DB::table('pos_order_packaging_lines')->insertGetId($row + ['company_id' => $companyId, 'order_type' => $type,
            'ingredient_id' => null, 'product_id' => null, 'unit' => null, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function latteLine(int $qty = 1, array $addonIds = []): array
    {
        return $this->line($this->latte, $qty, 1500, $addonIds);
    }

    /** order.create (+ order.pay unless $pay is false); returns the order uuid. */
    private function sell(string $type, array $lines, ?array $payments = null): string
    {
        $order = $this->p4Order($lines, 0, null, ['order_type' => $type]);
        $payments ??= [['method' => 'cash', 'amount_baisas' => $order['grand_total_baisas'], 'change_given_baisas' => 0]];
        $at = now()->toIso8601String();
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.create', $order), ['client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay', 'client_timestamp' => $at,
            'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at, 'payments' => $payments]]]);

        return $order['uuid'];
    }

    private function void(string $uuid): void
    {
        $this->pushProcessed(self::TOKEN, [['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $uuid, 'reason' => 'mistake']]]);
    }

    private function shelf(int $productId): float
    {
        return (float) DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', $productId)->value('stock_qty');
    }

    /** @return array<string, float> what left the shelves and stores, per item */
    private function taken(): array
    {
        return [
            'milk' => 10000 - (float) $this->branchBalance(self::MILK), 'beans' => 10000 - (float) $this->branchBalance(self::BEANS),
            'sugar' => 10000 - (float) $this->branchBalance(self::SUGAR), 'napkin' => 10000 - (float) $this->branchBalance(self::NAPKIN),
            'cup' => 100 - $this->shelf($this->cup), 'lid' => 100 - $this->shelf($this->lid), 'large_cup' => 100 - $this->shelf($this->largeCup),
            'paper_bag' => 100 - $this->shelf($this->paperBag), 'delivery_bag' => 100 - $this->shelf($this->deliveryBag),
        ];
    }

    /** @param array<string, float> $expected only the non-zero ones */
    private function assertTaken(array $expected): void
    {
        $this->assertEquals(array_replace(array_fill_keys(array_keys($this->taken()), 0.0), $expected), $this->taken());
    }

    private function order(string $uuid): Order
    {
        return Order::query()->where('uuid', $uuid)->sole();
    }

    /** Every ledger row of the order sums to zero per item (a void restored exactly what the sale took). */
    private function assertLedgerNetsToZero(Order $order): void
    {
        foreach (DB::table('pos_stock_movements')->where('reference_type', 'pos_orders')->where('reference_id', $order->id)
            ->selectRaw('ingredient_id, SUM(quantity) AS net')->groupBy('ingredient_id')->get() as $row) {
            $this->assertEqualsWithDelta(0.0, (float) $row->net, 0.00001, 'ingredient '.$row->ingredient_id);
        }
        foreach (DB::table('pos_product_stock_movements')->where('reference_type', 'pos_orders')->where('reference_id', $order->id)
            ->selectRaw('product_id, SUM(quantity) AS net')->groupBy('product_id')->get() as $row) {
            $this->assertEqualsWithDelta(0.0, (float) $row->net, 0.00001, 'product '.$row->product_id);
        }
    }

    // --------------------------------------------------------------- tests

    public function test_a_latte_sold_dine_in_takes_no_cup_or_lid_and_to_go_takes_both_and_voids_restore_exactly(): void
    {
        $dineIn = $this->sell('dine_in', [$this->latteLine(2)]);
        $this->assertTaken(['milk' => 400.0, 'beans' => 36.0, 'napkin' => 1.0]);
        $order = $this->order($dineIn);
        $this->assertSame('dine_in', $order->stock_order_type);
        $this->assertEquals(['order_type' => 'dine_in', 'lines' => [
            ['type' => 'ingredient', 'ingredient_id' => self::NAPKIN, 'qty' => 1.0, 'unit' => 'piece', 'unit_cost' => 0.005],
        ]], $order->packaging_snapshot_json);
        // The copies carry the ticks; untagged lines stay exactly as before.
        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertEquals([
            ['ingredient_id' => self::MILK, 'qty' => 200.0, 'unit' => 'ml', 'unit_cost' => 0.0004],
            ['ingredient_id' => self::BEANS, 'qty' => 18.0, 'unit' => 'g', 'unit_cost' => 0.02],
        ], $item->recipe_snapshot_json);
        $this->assertEquals([
            ['product_id' => $this->cup, 'qty' => 1.0, 'order_types' => 14],
            ['product_id' => $this->lid, 'qty' => 1.0, 'order_types' => 14],
        ], $item->component_snapshot_json);

        $toGo = $this->sell('to_go', [$this->latteLine(2)]);
        $this->assertTaken(['milk' => 800.0, 'beans' => 72.0, 'napkin' => 1.0, 'cup' => 2.0, 'lid' => 2.0, 'paper_bag' => 1.0]);
        $this->assertEquals(['order packaging (to_go)'], DB::table('pos_product_stock_movements')
            ->where('product_id', $this->paperBag)->pluck('note')->all());

        // `car` (curb-side) is packed like to go.
        $car = $this->sell('car', [$this->latteLine(1)]);
        $this->assertSame('to_go', $this->order($car)->stock_order_type);
        $this->assertTaken(['milk' => 1000.0, 'beans' => 90.0, 'napkin' => 1.0, 'cup' => 3.0, 'lid' => 3.0, 'paper_bag' => 2.0]);

        foreach ([$dineIn, $toGo, $car] as $uuid) {
            $this->void($uuid);
            $this->assertLedgerNetsToZero($this->order($uuid));
        }
        $this->assertTaken([]);
    }

    public function test_the_large_option_swaps_the_cups_only_for_to_go_and_a_to_go_only_removal_never_reduces_a_dine_in_base(): void
    {
        $lessMilk = $this->p4Addons($this->latte, ['Less milk to go' => '0.000'], ['name' => 'Milk'])['Less milk to go'];
        $this->consumption($lessMilk, ['ingredient_id' => self::MILK, 'direction' => 'remove', 'quantity' => '50', 'unit' => 'ml', 'order_types' => 4]);

        $dineIn = $this->sell('dine_in', [$this->latteLine(1, [$this->large, $lessMilk])]);
        // Dine in: +100 milk from Large, the to-go-only removal does not apply, no cups at all.
        $this->assertTaken(['milk' => 300.0, 'beans' => 18.0, 'napkin' => 1.0]);
        $copy = OrderItem::query()->where('order_id', $this->order($dineIn)->id)->sole()->addons()->orderBy('id')->first()->consumption_snapshot_json;
        $this->assertEquals([
            ['type' => 'product', 'product_id' => $this->cup, 'direction' => 'remove', 'qty' => 1.0, 'order_types' => 14],
            ['type' => 'product', 'product_id' => $this->largeCup, 'direction' => 'add', 'qty' => 1.0, 'order_types' => 14],
            ['type' => 'ingredient', 'ingredient_id' => self::MILK, 'direction' => 'add', 'qty' => 100.0, 'unit' => 'ml', 'unit_cost' => 0.0004],
        ], $copy);

        $toGo = $this->sell('to_go', [$this->latteLine(1, [$this->large, $lessMilk])]);
        // To go: the paper cup is swapped for a large cup; milk 200 + 100 − 50.
        $this->assertTaken(['milk' => 550.0, 'beans' => 36.0, 'napkin' => 1.0, 'lid' => 1.0, 'large_cup' => 1.0, 'paper_bag' => 1.0]);

        $this->void($dineIn);
        $this->void($toGo);
        $this->assertTaken([]);
    }

    public function test_per_order_packaging_is_taken_once_for_a_five_item_delivery_hand_off_and_restored_on_void(): void
    {
        DB::table('pos_delivery_providers')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Talabat',
            'commission_percent' => 20.00, 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->p4Order([$this->latteLine(3), $this->latteLine(2, [$this->large])], 0, null, ['order_type' => 'delivery']);
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.create', $order), ['client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.deliver', 'client_timestamp' => now()->toIso8601String(), 'payload' => [
                'order_uuid' => $order['uuid'], 'delivered_at' => now()->toIso8601String(),
                'delivery' => ['provider_id' => 1, 'reference' => 'TLB-1', 'customer_phone' => '91234567', 'driver_phone' => '99887766']]]]);

        // Five lattes (two Large): 5 lids, 3 paper cups + 2 large cups; ONE delivery bag and 3 napkins.
        $this->assertTaken(['milk' => 1200.0, 'beans' => 90.0, 'cup' => 3.0, 'lid' => 5.0, 'large_cup' => 2.0,
            'delivery_bag' => 1.0, 'napkin' => 3.0]);
        $stamped = $this->order($order['uuid']);
        $this->assertSame('delivery', $stamped->stock_order_type);
        $this->assertEquals(['order_type' => 'delivery', 'lines' => [
            ['type' => 'product', 'product_id' => $this->deliveryBag, 'qty' => 1.0],
            ['type' => 'ingredient', 'ingredient_id' => self::NAPKIN, 'qty' => 3.0, 'unit' => 'piece', 'unit_cost' => 0.005],
        ]], $stamped->packaging_snapshot_json);
        $napkin = DB::table('pos_stock_movements')->where('ingredient_id', self::NAPKIN)->sole();
        $this->assertEquals(['sale_consumption', 'order packaging (delivery)', -3.0],
            [$napkin->movement_type, $napkin->note, (float) $napkin->quantity]);

        // The merchant edits the list afterwards: the void restores what was taken.
        DB::table('pos_order_packaging_lines')->where('order_type', 'delivery')->update(['quantity' => '9']);
        $this->void($order['uuid']);
        $this->assertTaken([]);
        $this->assertLedgerNetsToZero($stamped);
    }

    public function test_a_quick_order_turned_to_go_before_payment_takes_the_to_go_lines_and_packaging(): void
    {
        $this->packaging('quick', ['ingredient_id' => self::SUGAR, 'quantity' => '7', 'unit' => 'g']);
        $order = $this->p4Order([$this->latteLine(1)], 0, null, ['order_type' => 'quick']);
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.hold', $order)]);
        $held = OrderItem::query()->sole();

        // The cashier switches the recalled order to To go; the line is unchanged (kept copy).
        $order['order_type'] = 'to_go';
        $at = now()->toIso8601String();
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.create', $order), ['client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay', 'client_timestamp' => $at, 'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at,
                'payments' => [['method' => 'cash', 'amount_baisas' => 1500, 'change_given_baisas' => 0]]]]]);

        $this->assertSame($held->component_snapshot_json, OrderItem::query()->sole()->component_snapshot_json);
        $this->assertSame('to_go', $this->order($order['uuid'])->stock_order_type);
        $this->assertTaken(['milk' => 200.0, 'beans' => 18.0, 'cup' => 1.0, 'lid' => 1.0, 'paper_bag' => 1.0]);
    }

    public function test_an_order_stocked_before_this_release_is_restored_in_full_on_void(): void
    {
        // Sold dine in while nothing was ticked: every line was taken.
        DB::table('pos_product_components')->update(['order_types' => 15]);
        DB::table('pos_order_packaging_lines')->delete();
        $uuid = $this->sell('dine_in', [$this->latteLine(2)]);
        $this->assertTaken(['milk' => 400.0, 'beans' => 36.0, 'cup' => 2.0, 'lid' => 2.0]);
        // As written by the code before this release: no stamp, no packaging,
        // and a legacy line without a component copy (live components).
        $order = $this->order($uuid);
        DB::table('pos_orders')->where('id', $order->id)->update(['stock_order_type' => null, 'packaging_snapshot_json' => null]);
        DB::table('pos_order_items')->where('order_id', $order->id)->update(['component_snapshot_json' => null]);

        // The merchant now ticks cup and lid off for dine in and adds dine-in packaging.
        DB::table('pos_product_components')->update(['order_types' => 14]);
        $this->packaging('dine_in', ['ingredient_id' => self::NAPKIN, 'quantity' => '1', 'unit' => 'piece']);

        $this->void($uuid);
        $this->assertTaken([]);
        $this->assertLedgerNetsToZero($order);
    }

    public function test_a_table_bill_from_three_staff_rounds_takes_its_packaging_once(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable('PK T1');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        foreach ([1, 2, 1] as $qty) {
            $id = (string) Str::uuid();
            $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
                'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
                'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id, 'queued_offline' => false,
                    'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(),
                    'lines' => [['product_id' => $this->latte, 'qty' => $qty, 'addon_ids' => [], 'notes' => null]]],
            ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        }
        $order = Order::query()->sole();
        $this->assertSame(3, OrderItem::query()->where('order_id', $order->id)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [['client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => 6000]]]]]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        // Four lattes dine in: no cups or lids; the dine-in napkin once for the bill.
        $this->assertSame('dine_in', $order->refresh()->stock_order_type);
        $this->assertTaken(['milk' => 800.0, 'beans' => 72.0, 'napkin' => 1.0]);
    }

    public function test_a_split_payment_takes_the_packaging_once(): void
    {
        $this->sell('to_go', [$this->latteLine(2)], [
            ['method' => 'cash', 'amount_baisas' => 1000, 'change_given_baisas' => 0],
            ['method' => 'bank_pos', 'amount_baisas' => 1000],
            ['method' => 'cash', 'amount_baisas' => 1000, 'change_given_baisas' => 0],
        ]);

        $this->assertTaken(['milk' => 400.0, 'beans' => 36.0, 'cup' => 2.0, 'lid' => 2.0, 'paper_bag' => 1.0]);
        $this->assertSame(1, DB::table('pos_product_stock_movements')->where('product_id', $this->paperBag)->count());
    }

    public function test_a_resent_paid_order_takes_nothing_twice(): void
    {
        $order = $this->p4Order([$this->latteLine(1)], 0, null, ['order_type' => 'to_go']);
        $at = now()->toIso8601String();
        $pay = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
            'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at,
                'payments' => [['method' => 'cash', 'amount_baisas' => 1500, 'change_given_baisas' => 0]]]];
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.create', $order), $pay]);
        $taken = $this->taken();
        $rows = [DB::table('pos_stock_movements')->count(), DB::table('pos_product_stock_movements')->count()];
        $stamp = DB::table('pos_orders')->select('stock_order_type', 'packaging_snapshot_json')->first();

        // The same event again (deduplicated), the pay under a new id (refused) and a re-sent order.create (refused).
        $this->p4Push(self::TOKEN, [$pay]);
        $this->p4Push(self::TOKEN, [array_replace($pay, ['client_event_id' => (string) Str::uuid()])]);
        $this->p4Push(self::TOKEN, [$this->p4Event('order.create', array_replace($order, ['order_type' => 'delivery']))]);

        $this->assertSame($taken, $this->taken());
        $this->assertSame($rows, [DB::table('pos_stock_movements')->count(), DB::table('pos_product_stock_movements')->count()]);
        $this->assertEquals($stamp, DB::table('pos_orders')->select('stock_order_type', 'packaging_snapshot_json')->first());
    }

    public function test_prep_explosion_keeps_the_tag_of_the_line_that_uses_the_prep(): void
    {
        $this->seedPrepKitchen();
        // The garlic sauce (a prep item) only goes with to-go shawarmas; the direct garlic always.
        DB::table('pos_product_recipes')->where('product_id', self::SHAWARMA)->where('ingredient_id', self::SAUCE)->update(['order_types' => 4]);

        $dineIn = $this->sell('dine_in', [$this->line(self::SHAWARMA, 1)]);
        $copy = OrderItem::query()->where('order_id', $this->order($dineIn)->id)->sole()->recipe_snapshot_json;
        $this->assertEquals([
            ['ingredient_id' => self::CHICKEN, 'qty' => 150.0, 'unit' => 'g', 'unit_cost' => 0.004],
            ['ingredient_id' => self::BREAD, 'qty' => 1.0, 'unit' => 'piece', 'unit_cost' => 0.05],
            ['ingredient_id' => self::GARLIC, 'qty' => 6.0, 'unit' => 'g', 'unit_cost' => 0.002, 'order_types' => 4],
            ['ingredient_id' => self::OIL, 'qty' => 21.0, 'unit' => 'ml', 'unit_cost' => 0.0015, 'order_types' => 4],
            ['ingredient_id' => self::LEMON, 'qty' => 3.0, 'unit' => 'ml', 'unit_cost' => 0.001, 'order_types' => 4],
            ['ingredient_id' => self::GARLIC, 'qty' => 5.0, 'unit' => 'g', 'unit_cost' => 0.002],
        ], $copy);
        // Dine in: only the direct garlic; no oil or lemon.
        $this->assertEquals([995.0, 1000.0, 1000.0], [$this->branchBalance(self::GARLIC), $this->branchBalance(self::OIL), $this->branchBalance(self::LEMON)]);

        $this->sell('to_go', [$this->line(self::SHAWARMA, 1)]);
        $this->assertEquals([984.0, 979.0, 997.0], [$this->branchBalance(self::GARLIC), $this->branchBalance(self::OIL), $this->branchBalance(self::LEMON)]);
        $this->assertPrepHasNoStock();
    }

    public function test_same_item_lines_with_disjoint_ticks_take_only_the_matching_line_and_an_overlap_is_refused(): void
    {
        $napkinItem = $this->item('Napkin pack');
        // Napkin ×1 for dine in, ×3 for to go and delivery; sugar 5 g dine in, 10 g otherwise.
        $this->itemLine($this->latte, $napkinItem, '1', 1);
        $this->itemLine($this->latte, $napkinItem, '3', 12);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => $this->latte, 'ingredient_id' => self::SUGAR, 'quantity' => '5', 'unit_at_set' => 'g', 'sort_order' => 3,
                'order_types' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $this->latte, 'ingredient_id' => self::SUGAR, 'quantity' => '10', 'unit_at_set' => 'g', 'sort_order' => 4,
                'order_types' => 14, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->sell('dine_in', [$this->latteLine(1)]);
        $this->assertEquals([99.0, 9995.0], [$this->shelf($napkinItem), $this->branchBalance(self::SUGAR)]);
        $this->sell('to_go', [$this->latteLine(1)]);
        $this->assertEquals([96.0, 9985.0], [$this->shelf($napkinItem), $this->branchBalance(self::SUGAR)]);
        $this->sell('quick', [$this->latteLine(1)]);
        $this->assertEquals([96.0, 9975.0], [$this->shelf($napkinItem), $this->branchBalance(self::SUGAR)]);

        // Two lines of one item that share a tick are refused by the database.
        $this->expectException(QueryException::class);
        $this->itemLine($this->latte, $napkinItem, '2', 3);
    }

    public function test_an_overlapping_recipe_or_add_on_line_is_refused_too(): void
    {
        $refused = 0;
        foreach ([
            fn () => DB::table('pos_product_recipes')->insert(['product_id' => $this->latte, 'ingredient_id' => self::MILK, 'quantity' => '1',
                'unit_at_set' => 'ml', 'order_types' => 8, 'created_at' => now(), 'updated_at' => now()]),
            fn () => $this->consumption($this->large, ['ingredient_id' => self::MILK, 'direction' => 'add', 'quantity' => '5', 'unit' => 'ml', 'order_types' => 2]),
            fn () => $this->consumption($this->large, ['component_product_id' => $this->largeCup, 'direction' => 'add', 'quantity' => '1', 'order_types' => 4]),
        ] as $insert) {
            try {
                $insert();
            } catch (QueryException) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused);

        // Disjoint ticks are accepted, and a removal line of the same item is another direction.
        $this->consumption($this->large, ['component_product_id' => $this->largeCup, 'direction' => 'add', 'quantity' => '1', 'order_types' => 1]);
        $this->consumption($this->large, ['component_product_id' => $this->largeCup, 'direction' => 'remove', 'quantity' => '1', 'order_types' => 15]);
        $this->assertSame(3, DB::table('pos_addon_consumptions')->where('component_product_id', $this->largeCup)->count());
    }

    public function test_qr_quick_staff_round_and_qr_table_round_copies_keep_the_ticks(): void
    {
        $expected = [
            ['product_id' => $this->cup, 'qty' => 1.0, 'order_types' => 14],
            ['product_id' => $this->lid, 'qty' => 1.0, 'order_types' => 14],
        ];
        $session = $this->p4QrSession();
        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout([
            ['product_id' => $this->latte, 'qty' => 1, 'addon_ids' => [$this->large], 'notes' => ''],
        ]))->assertStatus(201);
        $quick = OrderItem::query()->sole();
        $this->assertSame('quick', $quick->order->order_type);
        $this->assertEquals($expected, $quick->component_snapshot_json);
        $this->assertSame(14, $quick->addons()->sole()->consumption_snapshot_json[0]['order_types']);
        OrderItem::query()->delete();

        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $id = (string) Str::uuid();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $this->latte, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertEquals($expected, OrderItem::query()->sole()->component_snapshot_json);
        OrderItem::query()->delete();

        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $bind = app(BindQrTableSessionAction::class)->handle($this->seatingTable('PK T9')->qr_token, 'owner');
        $round = $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 'owner'])
            ->postJson('/api/v1/public/qr/table-round', ['client_request_id' => 'pk-round', 'phone' => '91234567',
                'lines' => [['product_id' => $this->latte, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]])->assertCreated();
        // Ticks change before staff confirm: the round keeps its submit-time copies.
        DB::table('pos_product_components')->update(['order_types' => 15]);
        app(ConfirmDineInQrRoundAction::class)->handle($device, $round->json('data.round.id'));
        $this->assertEquals($expected, OrderItem::query()->sole()->component_snapshot_json);
    }

    public function test_prepared_cancellation_waste_takes_only_the_lines_of_the_order_type(): void
    {
        $this->seedPrepKitchen();
        DB::table('pos_product_recipes')->where('product_id', self::SHAWARMA)->where('ingredient_id', self::SAUCE)->update(['order_types' => 4]);
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $id = (string) Str::uuid();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $at = now()->startOfSecond()->toIso8601String();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.cancel_line', 'client_timestamp' => $at,
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'product_id' => self::SHAWARMA, 'addon_ids' => [], 'notes' => null,
                'qty' => 1, 'prepared' => true, 'cancelled_at' => $at, 'staff_id' => 7],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        // A table bill is dine in: the to-go sauce was never made.
        $wasted = DB::table('pos_waste_records')->orderBy('ingredient_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->ingredient_id => (float) $row->quantity])->all();
        $this->assertEquals([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $wasted);
    }

    public function test_the_device_config_is_unchanged_by_the_ticks(): void
    {
        DB::table('pos_product_components')->update(['order_types' => 15]);
        DB::table('pos_addon_consumptions')->update(['order_types' => 15]);
        $config = fn (): array => array_intersect_key($this->withToken(self::TOKEN)->getJson('/api/v1/device/config')->assertOk()->json('data'),
            array_flip(['products', 'addon_groups', 'ingredients', 'branch_stock']));
        $before = $config();

        DB::table('pos_product_components')->update(['order_types' => 14]);
        DB::table('pos_addon_consumptions')->update(['order_types' => 4]);
        DB::table('pos_product_recipes')->update(['order_types' => 1]);
        $this->assertSame($before, $config());
        $this->assertStringNotContainsString('order_types', (string) json_encode($before));
    }

    public function test_a_stamped_order_reuses_its_packaging_and_a_fully_cancelled_bill_takes_none(): void
    {
        $order = $this->p4Order([$this->latteLine(1)], 0, null, ['order_type' => 'to_go']);
        $this->pushProcessed(self::TOKEN, [$this->p4Event('order.create', $order)]);
        $model = $this->order($order['uuid']);
        $consume = app(ConsumeInventoryAction::class);

        // Every line cancelled: no packaging is frozen.
        OrderItem::query()->update(['qty' => 0]);
        DB::transaction(fn () => $consume->consume($model->fresh()));
        $this->assertSame('to_go', $model->fresh()->stock_order_type);
        $this->assertNull($model->fresh()->packaging_snapshot_json);
        $this->assertTaken([]);

        // An already stamped order keeps its frozen packaging, never rebuilds it.
        OrderItem::query()->update(['qty' => 1]);
        DB::table('pos_orders')->update(['stock_order_type' => 'delivery', 'packaging_snapshot_json' => json_encode(['order_type' => 'delivery',
            'lines' => [['type' => 'product', 'product_id' => $this->deliveryBag, 'qty' => 2]]])]);
        DB::transaction(fn () => $consume->consume($model->fresh()));
        $this->assertTaken(['milk' => 200.0, 'beans' => 18.0, 'cup' => 1.0, 'lid' => 1.0, 'delivery_bag' => 2.0]);
    }

    public function test_a_packaging_line_naming_another_companys_item_or_a_prep_item_is_skipped_and_the_sale_settles(): void
    {
        DB::table('pos_ingredients')->insert([
            $this->ingredientRow(90, 'Foreign napkin', 'piece', '0.001000', companyId: 200),
            $this->ingredientRow(91, 'House sauce', 'ml', '0', isPrep: true, yield: '100'),
        ]);
        $this->packaging('to_go', ['ingredient_id' => 90, 'quantity' => '1', 'unit' => 'piece', 'sort_order' => 1]);
        $this->packaging('to_go', ['ingredient_id' => 91, 'quantity' => '1', 'unit' => 'ml', 'sort_order' => 2]);
        $this->packaging('to_go', ['product_id' => $this->p4Product('Foreign bag', '0.000', ['company_id' => 200]), 'sort_order' => 3, 'quantity' => '1']);
        Log::spy();

        $uuid = $this->sell('to_go', [$this->latteLine(1)]);

        $this->assertSame('paid', $this->order($uuid)->status);
        $this->assertEquals([['type' => 'product', 'product_id' => $this->paperBag, 'qty' => 1.0]], $this->order($uuid)->packaging_snapshot_json['lines']);
        $this->assertFalse(DB::table('pos_stock_movements')->whereIn('ingredient_id', [90, 91])->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Order packaging lines skipped'))->once();
    }

    public function test_an_offline_sale_uses_the_ticks_in_force_when_it_was_sold(): void
    {
        // Sold at 08:00 while the cup and the milk were "to go only"; at 08:30 the merchant ticked the milk back for every type.
        DB::table('pos_product_recipes')->where('ingredient_id', self::MILK)->update(['order_types' => 15]);
        DB::table('pos_product_recipe_versions')->insert(['product_id' => $this->latte, 'edited_at' => '2026-10-06 08:30:00',
            'recipe_json' => json_encode([['ingredient_id' => self::MILK, 'quantity' => '200', 'unit' => 'ml', 'order_types' => 4],
                ['ingredient_id' => self::BEANS, 'quantity' => '18', 'unit' => 'g']])]);
        $order = $this->p4Order([$this->latteLine(1)], 0, null, ['order_type' => 'dine_in', 'opened_at' => '2026-10-06T08:00:00+00:00']);
        $event = $this->p4Event('order.create', $order);
        $event['client_timestamp'] = '2026-10-06T08:00:00+00:00';
        $this->pushProcessed(self::TOKEN, [$event]);

        $this->assertEquals([
            ['ingredient_id' => self::MILK, 'qty' => 200.0, 'unit' => 'ml', 'unit_cost' => 0.0004, 'order_types' => 4],
            ['ingredient_id' => self::BEANS, 'qty' => 18.0, 'unit' => 'g', 'unit_cost' => 0.02],
        ], OrderItem::query()->sole()->recipe_snapshot_json);
    }

    public function test_another_companys_packaging_list_is_never_read(): void
    {
        $this->packaging('to_go', ['product_id' => $this->paperBag, 'quantity' => '5'], 200);

        $this->sell('to_go', [$this->latteLine(1)]);
        $this->assertTaken(['milk' => 200.0, 'beans' => 18.0, 'cup' => 1.0, 'lid' => 1.0, 'paper_bag' => 1.0]);
    }
}
